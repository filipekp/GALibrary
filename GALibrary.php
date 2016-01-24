<?php
/**
 * Library for communication with google analytics.
 * PHP support: >5.5.x
 *
 * @author Pavel Filípek <www.filipek-czech.cz>
 * @copyright © 2016, Pavel Filípek
 * @build 2016-01-24
 */
class GALibrary {
  /** Google Analytics measurement code. For example: UA-XXXXXXX-X  */
  private $_googleAnalyticsCode = '';
  /** Email for send error report */
  private $_adminEmail = NULL;
  
  /**
   * Set instance for GALibrary.
   * 
   * @param sting $GAIdentifier fro example: UA-XXXXXXX-X
   */
  public function __construct($GAIdentifier) {
    $this->_googleAnalyticsCode = $GAIdentifier;
  }
  
  /**
   * Generate UUID v4 function - needed to generate a CID when one isn't available.
   * 
   * @return string
   */
  private function gaGenUUID() {
    $properties = [
      mt_rand(0, 0xffff), mt_rand(0, 0xffff), // 32 bits for "time_low"
      mt_rand(0, 0xffff),                     // 16 bits for "time_mid"
      mt_rand(0, 0x0fff) | 0x4000,            // 16 bits for "time_hi_and_version",
                                              // four most significant bits holds version number 4 
      mt_rand(0, 0x3fff) | 0x8000,            // 16 bits, 8 bits for "clk_seq_hi_res",
                                              // 8 bits for "clk_seq_low",
                                              // two most significant bits holds zero and one for variant DCE1.1
      mt_rand(0, 0xffff), mt_rand(0, 0xffff), mt_rand(0, 0xffff) // 48 bits for "node"
    ];
    
    return vsprintf('%04x%04x-%04x-%04x-%04x-%04x%04x%04x', $properties);
  }
  
  /**
   * Handle the parsing of the _ga cookie or setting it to a unique identifier.
   * 
   * @return string
   */
  private function gaParseCookie() {
    $cookies = filter_input_array(INPUT_COOKIE);
    
    if (($googleCookie = self::item($cookies, '_ga'))) {
      list($version, $domainDepth, $cid1, $cid2) = split('[\.]', $googleCookie, 4);
      $contents = [
        'version' => $version,
        'domainDepth' => $domainDepth,
        'cid' => $cid1 . '.' . $cid2
      ];
      
      $cid = $contents['cid'];
    } else {
      $cid = $this->gaGenUUID();
    }
    
    return $cid;
  }

  /**
   * Method for send data to GA.
   * See https://developers.google.com/analytics/devguides/collection/protocol/v1/devguide
   * 
   * @param array $data
   * @return boolean
   */
  private function gaFireHit($data = NULL) {
    if ($data) {
      $getString  = 'https://ssl.google-analytics.com/collect';
      $getString .= '?payload_data&';
      $getString .= http_build_query($data);
      
      $result = file_get_contents($getString);

      if (!is_null($this->_adminEmail) && !$result) {
        // send erreor log to admin email
        @error_log($getString, 1, $this->_adminEmail);
      }

      return $result;
    }
    
    return false;
  }
  
  /**
   * Register pageview.
   * 
   * @param array $data
   * @return array
   */
  private function pageviewMethod(array $dataDefault, $info) {
    // Send PageView hit
    $data = $dataDefault + [
      't' => 'pageview',        // pageview hit type
      'dt' => $info['title'],   // page title
      'dp' => $info['slug']     // page path => /home
    ];
    
    return $data;
  }
  
  /**
   * Generate data for ecommerce item.
   * 
   * @param array $dataDefault
   * @param array $item
   * 
   * @return array
   */
  private function ecommerceItemMethod(array $dataDefault, $item) {
    $data = $dataDefault + [
      't' => 'item',
      'in' => urlencode(self::item($item, 'name')),         // item name
      'ip' => urlencode((float)self::item($item, 'price')), // item price
      'iq' => (float)self::item($item, 'quantity'),         // item quantity
      'ic' => urlencode(self::item($item, 'sku')),          // item SKU
      'iv' => urlencode('SI')
    ];
    
    return $data;
  }
  
  /**
   * Register ecommerce.
   * 
   * @param array $data
   * @return array
   */
  private function ecommerceMethod(array $dataDefault, $info) {
    // Set up Transaction params
    $transactionID = uniqid();
    $currencyCode = self::item($info, 'currencyCode');

    // Send Transaction hit
    $data = $dataDefault + [
      't' => 'transaction',
      'ti' => $transactionID,             // Transaction ID
      'ta' => urlencode('SI'),            // Affiliation.
      'tr' => self::item($info, 'price'), // Revenue.
      'cu' => $currencyCode               // Currency code
    ];
    
    return [
      'transaction' => $data,
      'items'       => (array)array_map(function($item) use ($dataDefault, $transactionID, $currencyCode) {
        $data = $dataDefault + [
          'ti'  => $transactionID,  // Transaction ID
          'cu'  => $currencyCode    // Currency code
        ];
        
        return $this->ecommerceItemMethod($data, $item);
      }, self::item($info, 'items', []))
    ];
  }
  
  /**
   * Function for call GA measurement method.
   * 
   * @param string $method
   * @param array $info
   */
  public function gaBuildHit($method = NULL, $info = NULL) {
    if ($method && $info) {
      // Standard params
      $dataDefault = [
        'v' => 1,
        'tid' => $this->_googleAnalyticsCode,
        'cid' => $this->gaParseCookie()
      ];

      switch($method) {
        case 'pageview':
          $this->gaFireHit($this->pageviewMethod($dataDefault, $info));
        break;
        case 'ecommerce':
          $resData = $this->ecommerceMethod($dataDefault, $info);
          $this->gaFireHit(self::item($resData, 'transaction'));
          
          foreach((array)self::item($resData, 'items') as $item) {
            $this->gaFireHit($item);
          }
        break;
        default:
          throw new Exception('Not passed or unknown method.');
      }
    }
  }
  
  /**
   * "Secure" get item from array.
   * 
   * @param array $array
   * @param array|string $path
   * @param mixed $default
   * 
   * @return mixed
   */
  private static function item($array, $path, $default = NULL) {
    $current = $array;
    foreach ((array)$path as $key) {
      if (((is_array($current)) ? array_key_exists($key, $current) : FALSE)) {
        $current = $current[$key];
      } else {
        return $default;
      }
    }
    
    return $current;
  }
  
  /**
   * Setter.
   * 
   * @param atring $adminEmail
   * @return \GALibrary
   */
  public function setAdminEmail($adminEmail) {
    $this->_adminEmail = $adminEmail;
    
    return $this;
  }
}
