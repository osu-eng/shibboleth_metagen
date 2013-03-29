<?php

class Metadata {

    // Set instance variables
  public $samlsign;
  public $key;
  public $max_age;
  public $contacts;

  public $providers;

  /**
   * Construct a new multi-provider using info from config.
   *
   * @param string $config path to config file
   */
  function __construct($config = NULL) {

    if (is_null($config)) {
      $config = dirname(__FILE__) . '/config.ini';
    }
    $this->parse($config);
  }

  /**
   * Returns a string with the contents of a metadata file.
   */
  function generate() {

    # Some necessary scripts/programs
    $metagen_cmd = __DIR__ . '/metagen.sh ';
    $samlsign = $this->samlsign;

    # Output file
    $metadata_file = __DIR__ . '/metadata.xml';
    $signed_metadata_file = __DIR__ . "/www/metadata.xml";

    # Create a list of contacts as arguments
    $contacts = '';
    foreach ($this->contacts as $contact) {
      $contacts .= " -t {$contact}";
    }

    # Generate an expiration date for the metadata
    $seconds = time() + $this->max_age;
    $valid_until = date('Y-m-d', $seconds) . 'T' . date('H:i:s', $seconds).'Z'; // '2011-04-14T09:45:26Z';

    foreach ($this->providers as $provider) {
      if (count($provider->names) > 0) {
        file_put_contents('/tmp/cert.pem', $provider->cert);
        file_put_contents($metadata_file, "<md:EntitiesDescriptor xmlns:md=\"urn:oasis:names:tc:SAML:2.0:metadata\" validUntil=\"{$valid_until}\" Name=\"https://engineering.osu.edu/aegir\">");
        $command = "$metagen_cmd $contacts -c /tmp/cert.pem "
          .' -e ' . $provider->entity
          .' -o "' . $provider->description . '"  '
          .' -h '. join(' -h ', $provider->names) . ' >> ' . $metadata_file;
        system($command);
        system ('rm -f /tmp/cert.pem');
        system("echo '</md:EntitiesDescriptor>' >> {$metadata_file}");
      }
      else {
        throw new Excecption("Aborting because $environment has no sites.\n");
      }
    }

    file_put_contents('/tmp/key.pem', trim($this->key));
    $command = "{$samlsign} -s -f {$metadata_file} -k /tmp/key.pem > $signed_metadata_file";
    system($command);
  }

  private function parse($path) {

    // Check config file
    if (!is_readable($path)) {
      throw new Exception('Config file not readable (' . $path . '). Perhaps you need to copy config.ini.sample to config.ini?');
    }
    $config = parse_ini_file($path, TRUE);
    if (!$config) {
      throw new Exception('Your config.ini file could not be parsed.');
    }

    $expected = array('key', 'samlsign', 'max_age', 'contacts');
    foreach ($expected as $field) {
      if (!array_key_exists($field, $config)) {
        throw new Excepction("Your config file is missing field: {$field}");
      }
    }

    if (!is_executable($config['samlsign'])) {
      throw new Exception("samlsign must be the path to an executable (" . $config['samlsign'] . ")");
    }

    if (!(is_numeric($config['max_age']) && ($config['max_age'] > 0))) {
      throw new Exception("max_age must be an integer greater than 0 (" . $config['max_age'] . ")");
    }

    if (!is_array($config['contacts'])) {
      throw new Exception("contacts should be specified using ini array syntax: contacts[] = \"John/Smith/smith.1@osu.edu\"");
    }

    foreach ($config['contacts'] as $contact) {
      if (!preg_match("~^[a-z| |-]+/[a-z| |-]+/.*@osu.edu$~i", $contact)) {
        throw new Exception("contacts should be entered like so: contacts[] = \"John/Smith/smith.1@osu.edu\"");
      }
    }

    // Set instance variables
    $this->samlsign = $config['samlsign'];
    $this->key = $config['key'];
    $this->max_age = $config['max_age'];
    $this->contacts = $config['contacts'];

    // Find / validate / create our environments
    foreach ($config as $key => $vals) {
      if (!in_array($key, $expected) && is_scalar($vals)) {
        throw new Exception ("Unexpected field: $vals");
      }
      if (!in_array($key, $expected)) {
        $this->providers[$key] = new Provider($key, $vals);
      }
    }
  }

}

