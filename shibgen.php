<?php


try {
  print "Beginning regeneration: " . date('Y-m-j H:i') . "\n";
  $mp = new MultiProvider();
  $mp->metadata();
  print "Finished regeneration\n";

}
catch (Exception $e) {
  print $e->getMessage();
  throw $e;
}

class MultiProvider {

  private $config;
  private $providers;
  private $names;

  /**
   * Construct a new multi-provider using info from config.
   *
   * @param string $config path to config file
   */
  function __construct($config = 'config.ini') {

    // Check config file
    if (!is_readable($config)) {
      throw new Exception('Config file not readable');
    }
    $this->config = parse_ini_file($config, TRUE);

    // Create a provider object for each environment
    $this->providers = array();
    foreach ($this->environments() as $environment) {
      $this->providers[$environment] = new Provider($this->config[$environment]);
    }
  }

  /**
   * Returns a list of environments identified in the config file.
   */
  function environments() {
    $environments = array();
    $not_environments = array('contact', 'key', 'max_age', 'samlsign');
    foreach (array_keys($this->config) as $key) {
      if (!in_array($key, $not_environments)) {
        $environments[] = $key;
      }
    }
    return $environments;
  }

  function names() {
    $this->names = array();
    foreach ($this->providers as $environment => $provider) {
      $this->names[$environment] = $provider->names();
    }
    return $this->names;
  }

  /**
   * Returns a string with the contents of a metadata file.
   */
  function metadata() {
    $this->names();

    # Some necessary scripts/programs
    $metagen_cmd = './metagen.sh ';           # included in repo
    $samlsign = $this->config['samlsign'];  # requires shibboleth installed

    # Output file
    $metadata_file = 'metadata.xml';

    # Create a list of contacts as arguments
    $contacts = '';
    foreach ($this->config['contact'] as $contact) {
      $contacts .= " -t {$contact}";
    }

    # Generate an expiration date for the metadata
    $seconds = time() + $this->config['max_age'];  
    $valid_until = date('Y-m-d', $seconds) . 'T' . date('H:i:s', $seconds).'Z'; // '2011-04-14T09:45:26Z';
    
    foreach ($this->environments() as $environment) {
      if (count($this->names[$environment]) > 0) {
        file_put_contents('/tmp/cert.pem', $this->config[$environment]['cert']);
        file_put_contents($metadata_file, "<md:EntitiesDescriptor xmlns:md=\"urn:oasis:names:tc:SAML:2.0:metadata\" validUntil=\"{$valid_until}\" Name=\"https://engineering.osu.edu/aegir\">");
        $command = "$metagen_cmd $contacts -c /tmp/cert.pem "
          .' -e ' . $this->config[$environment]['entity'] // https://engineering.osu.edu/aegir '
          .' -o "Engineering Drupal Environment "  '
          .' -h '. join(' -h ', $this->names[$environment]) . ' >> ' . $metadata_file;
        system($command);
        system ('rm -f /tmp/cert.pem');
        system("echo '</md:EntitiesDescriptor>' >> {$metadata_file}");
      }  
      else {
        throw new Excecption("Aborting because $environment has no sites.\nThe signed metadata file has not been replaced.\n"); 
      }
    }
    
    file_put_contents('/tmp/key.pem', trim($this->config['key']));
    $command = "{$samlsign} -s -f {$metadata_file} -k /tmp/key.pem > {$metadata_file}.signed";  // this didn't like the output being the metadata.xml file
    system($command);
    return file_get_contents($metadata_file . '.signed');
  }

}

class Provider {

  public $url;
  public $sites;

  private $user;
  private $pass;
  private $headers;

  function __construct($hash) {
    $this->url = $hash['url'];
    $this->user = $hash['user'];
    $this->pass = $hash['pass'];

    // initialize some arrays
    $this->headers = array();
    $this->aliases = array();

    // login
    $this->login();
  }

  /**
   * Login to drupal service
   */
  function login() {

    // necessary or the response is empty:
    $headers = array('Content-Type' => 'application/x-www-form-urlencoded');

    // Login
    $data = array(
      'username' => $this->user,
      'password' => $this->pass,
      );

    $data = http_build_query($data, '', '&');
    $response = my_http_request($this->url . 'hosting_api/user/login', $headers, 'POST', $data);

    if ($response->code != 200) {
      throw new Exception("Bad code: {$response->code} for {$this->url}hosting_api/user/login");
    }

    $data = json_decode($response->data);

    // Store cookie/header so we can identify our session later
    $this->headers['Cookie'] = $data->session_name . '=' . $data->sessid;
  }

  /**
   * Returns a list of all domain names that need shibboleth.
   * @return array all domains names that need shibboleth.
   */
  function names() {

    $response = my_http_request($this->url . 'hosting_api/views/hosting_api_sites', $this->headers);
    if ($response->code != 200) {
      // should throw an exception here
      throw new Exception("Bad code: {$response->code}");
    }
    $sites = json_decode($response->data);
    if (count($sites) ==0) {
      throw new Exception ('No sites found, that is probably bad');
    }

    $aliases = array();
    foreach ($sites as $site) {
      if (in_array('shibboleth_available', $site->flags) || in_array('shibboleth_required', $site->flags)) {
        $this->aliases = array_merge($this->aliases, $site->aliases);
        array_push($aliases, $site->title); 
      }
    }

    # Let's eliminate any garbage urls (testing sites, extra urls, etc)
    $filtered = array();
    foreach (array_unique($aliases) as $alias) {
      if (preg_match('/.osu.edu$/', $alias) || preg_match('/.engineering.osu.edu$/', $alias)) {
        $filtered[] = $alias;
      }
    } 
    $this->aliases = $filtered;
    return $this->aliases;
  }
}

/**
 * This is a modified version of drupal_http_request.
 */
function my_http_request($url, $headers = array(), $method = 'GET', $data = NULL, $retry = 3, $timeout = 30.0) {
  global $db_prefix;

  $result = new stdClass();

  // Parse the URL and make sure we can handle the schema.
  $uri = parse_url($url);

  if ($uri == FALSE) {
    $result->error = 'unable to parse URL';
    $result->code = -1001;
    return $result;
  }

  if (!isset($uri['scheme'])) {
    $result->error = 'missing schema';
    $result->code = -1002;
    return $result;
  }

  timer_start(__FUNCTION__);

  switch ($uri['scheme']) {
    case 'http':
    case 'feed':
      $port = isset($uri['port']) ? $uri['port'] : 80;
      $host = $uri['host'] . ($port != 80 ? ':' . $port : '');
      $fp = @fsockopen($uri['host'], $port, $errno, $errstr, $timeout);
      break;
    case 'https':
      // Note: Only works for PHP 4.3 compiled with OpenSSL.
      $port = isset($uri['port']) ? $uri['port'] : 443;
      $host = $uri['host'] . ($port != 443 ? ':' . $port : '');
      $fp = @fsockopen('ssl://' . $uri['host'], $port, $errno, $errstr, $timeout);
      break;
    default:
      $result->error = 'invalid schema ' . $uri['scheme'];
      $result->code = -1003;
      return $result;
  }

  // Make sure the socket opened properly.
  if (!$fp) {
    // When a network error occurs, we use a negative number so it does not
    // clash with the HTTP status codes.
    $result->code = -$errno;
    $result->error = trim($errstr);

    // Mark that this request failed. This will trigger a check of the web
    // server's ability to make outgoing HTTP requests the next time that
    // requirements checking is performed.
    // @see system_requirements()
    // variable_set('drupal_http_request_fails', TRUE);

    return $result;
  }

  // Construct the path to act on.
  $path = isset($uri['path']) ? $uri['path'] : '/';
  if (isset($uri['query'])) {
    $path .= '?' . $uri['query'];
  }

  // Create HTTP request.
  $defaults = array(
    // RFC 2616: "non-standard ports MUST, default ports MAY be included".
    // We don't add the port to prevent from breaking rewrite rules checking the
    // host that do not take into account the port number.
    'Host' => "Host: $host",
    'User-Agent' => 'User-Agent: Mozilla/6.0 (Windows NT 6.2; WOW64; rv:16.0.1) Gecko/20121011 Firefox/16.0.1',
  );

  // Only add Content-Length if we actually have any content or if it is a POST
  // or PUT request. Some non-standard servers get confused by Content-Length in
  // at least HEAD/GET requests, and Squid always requires Content-Length in
  // POST/PUT requests.
  $content_length = strlen($data);
  if ($content_length > 0 || $method == 'POST' || $method == 'PUT') {
    $defaults['Content-Length'] = 'Content-Length: ' . $content_length;
  }

  // If the server URL has a user then attempt to use basic authentication
  if (isset($uri['user'])) {
    $defaults['Authorization'] = 'Authorization: Basic ' . base64_encode($uri['user'] . (!empty($uri['pass']) ? ":" . $uri['pass'] : ''));
  }

  // If the database prefix is being used by SimpleTest to run the tests in a copied
  // database then set the user-agent header to the database prefix so that any
  // calls to other Drupal pages will run the SimpleTest prefixed database. The
  // user-agent is used to ensure that multiple testing sessions running at the
  // same time won't interfere with each other as they would if the database
  // prefix were stored statically in a file or database variable.
  if (is_string($db_prefix) && preg_match("/^simpletest\d+$/", $db_prefix, $matches)) {
    $defaults['User-Agent'] = 'User-Agent: ' . $matches[0];
  }

  foreach ($headers as $header => $value) {
    $defaults[$header] = $header . ': ' . $value;
  }

  $request = $method . ' ' . $path . " HTTP/1.0\r\n";
  $request .= implode("\r\n", $defaults);
  $request .= "\r\n\r\n";
  $request .= $data;

  $result->request = $request;

  // Calculate how much time is left of the original timeout value.
  $time_left = $timeout - timer_read(__FUNCTION__) / 1000;
  if ($time_left > 0) {
    stream_set_timeout($fp, floor($time_left), floor(1000000 * fmod($time_left, 1)));
    fwrite($fp, $request);
  }

  // Fetch response.
  $response = '';
  while (!feof($fp)) {
    // Calculate how much time is left of the original timeout value.
    $time_left = $timeout - timer_read(__FUNCTION__) / 1000;
    if ($time_left <= 0) {
      $result->code = HTTP_REQUEST_TIMEOUT;
      $result->error = 'request timed out';
      return $result;
    }
    stream_set_timeout($fp, floor($time_left), floor(1000000 * fmod($time_left, 1)));
    $chunk = fread($fp, 1024);
    $response .= $chunk;
  }
  fclose($fp);

  // Parse response headers from the response body.
  // Be tolerant of malformed HTTP responses that separate header and body with
  // \n\n or \r\r instead of \r\n\r\n.  See http://drupal.org/node/183435
  list($split, $result->data) = preg_split("/\r\n\r\n|\n\n|\r\r/", $response, 2);
  $split = preg_split("/\r\n|\n|\r/", $split);

  list($protocol, $code, $status_message) = explode(' ', trim(array_shift($split)), 3);
  $result->protocol = $protocol;
  $result->status_message = $status_message;

  $result->headers = array();

  // Parse headers.
  while ($line = trim(array_shift($split))) {
    list($header, $value) = explode(':', $line, 2);
    if (isset($result->headers[$header]) && $header == 'Set-Cookie') {
      // RFC 2109: the Set-Cookie response header comprises the token Set-
      // Cookie:, followed by a comma-separated list of one or more cookies.
      $result->headers[$header] .= ',' . trim($value);
    }
    else {
      $result->headers[$header] = trim($value);
    }
  }

  $responses = array(
    100 => 'Continue',
    101 => 'Switching Protocols',
    200 => 'OK',
    201 => 'Created',
    202 => 'Accepted',
    203 => 'Non-Authoritative Information',
    204 => 'No Content',
    205 => 'Reset Content',
    206 => 'Partial Content',
    300 => 'Multiple Choices',
    301 => 'Moved Permanently',
    302 => 'Found',
    303 => 'See Other',
    304 => 'Not Modified',
    305 => 'Use Proxy',
    307 => 'Temporary Redirect',
    400 => 'Bad Request',
    401 => 'Unauthorized',
    402 => 'Payment Required',
    403 => 'Forbidden',
    404 => 'Not Found',
    405 => 'Method Not Allowed',
    406 => 'Not Acceptable',
    407 => 'Proxy Authentication Required',
    408 => 'Request Time-out',
    409 => 'Conflict',
    410 => 'Gone',
    411 => 'Length Required',
    412 => 'Precondition Failed',
    413 => 'Request Entity Too Large',
    414 => 'Request-URI Too Large',
    415 => 'Unsupported Media Type',
    416 => 'Requested range not satisfiable',
    417 => 'Expectation Failed',
    500 => 'Internal Server Error',
    501 => 'Not Implemented',
    502 => 'Bad Gateway',
    503 => 'Service Unavailable',
    504 => 'Gateway Time-out',
    505 => 'HTTP Version not supported',
  );
  // RFC 2616 states that all unknown HTTP codes must be treated the same as the
  // base code in their class.
  if (!isset($responses[$code])) {
    $code = floor($code / 100) * 100;
  }

  switch ($code) {
    case 200: // OK
    case 304: // Not modified
      break;
    case 301: // Moved permanently
    case 302: // Moved temporarily
    case 307: // Moved temporarily
      $location = $result->headers['Location'];
      $timeout -= timer_read(__FUNCTION__) / 1000;
      if ($timeout <= 0) {
        $result->code = HTTP_REQUEST_TIMEOUT;
        $result->error = 'request timed out';
      }
      elseif ($retry) {
        $result = my_http_request($result->headers['Location'], $headers, $method, $data, --$retry, $timeout);
        $result->redirect_code = $result->code;
      }
      $result->redirect_url = $location;

      break;
    default:
      $result->error = $status_message;
  }

  $result->code = $code;
  return $result;
}

function timer_start($name) {
  global $timers;

  list($usec, $sec) = explode(' ', microtime());
  $timers[$name]['start'] = (float) $usec + (float) $sec;
  $timers[$name]['count'] = isset($timers[$name]['count']) ? ++$timers[$name]['count'] : 1;
}

function timer_read($name) {
  global $timers;

  if (isset($timers[$name]['start'])) {
    list($usec, $sec) = explode(' ', microtime());
    $stop = (float) $usec + (float) $sec;
    $diff = round(($stop - $timers[$name]['start']) * 1000, 2);

    if (isset($timers[$name]['time'])) {
      $diff += $timers[$name]['time'];
    }
    return $diff;
  }
}
