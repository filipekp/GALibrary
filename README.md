# GALibrary
PHP library for communication with Google Analytics.

# Install
Copy file GALibrary.php to your project folder.

# Example use
  Not remember to make require_once for use Class GALibrary.

  ```
  $gaLibraray = new GALibrary('UA-XXXXXXXXX-X');
  $gaLibraray->setAdminEmail('your@email.com')
             ->gaBuildHit('pageview', [
                'title' => 'title your page',
                'slug'  => 'path to page from root'
             ]);
  ```