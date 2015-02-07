Ctct package from: https://github.com/constantcontact/php-sdk/archive/master.zip
Extract only the 'src' directory
Patch the file CtCt/Services/ContactService.php, function getContact:
  change:
    return Contact::create(json_decode($response->body, true));
  to:
    // CIVIDESK - fix for: PGP Catchable fatal error: Argument 1 must be an array, null given
    $result = json_decode($response->body, true);
    if (is_array($result)) {
      return Contact::create($result);
    } else {
      return new Contact();
    }
