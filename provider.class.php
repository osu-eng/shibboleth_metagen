<?php

class Provider {

  # Public from config
  public $environment;
  public $entity;
  public $cert;

  # Public fetched from service
  public $sites;
  public $names;

  # Private from config
  private $url;
  private $user;
  private $pass;

  # Used to maintain session state
  private $headers;

  function __construct($env, $config) {

    $this->environment = $env;

    $this->parse($config);

    # Login and retrieve information
    $this->login();
    $this->loadSites();
    $this->loadNames();
  }

  private function parse($config) {

    $expected = array('cert', 'description', 'entity', 'pass', 'url', 'user');
    foreach ($expected as $field) {
      if (!array_key_exists($field, $config)) {
        throw new Exception("{$this->environment}: your config file is missing field: {$field}");
      }
    }
    foreach (array_keys($config) as $field) {
      if (!in_array($field, $expected)) {
        throw new Exception("{$this->environment}: unexpected field: {$field}");
      }
    }
    $this->url = $config['url'];
    $this->user = $config['user'];
    $this->pass = $config['pass'];
    $this->entity = $config['entity'];
    $this->description = $config['description'];
    $this->cert = $config['cert'];
  }

  /**
   * loads $this->sites with sites from the web service.
   */
  private function loadSites() {
    $response = my_http_request($this->url . 'hosting_api/views/hosting_api_sites', $this->headers);
    if ($response->code != 200) {
      // should throw an exception here
      throw new Exception("Bad code: {$response->code}");
    }
    $sites = json_decode($response->data);
    if (count($sites) ==0) {
      throw new Exception ('No sites found, that is probably bad');
    }
    $this->sites = $sites;
  }

  /**
   * Loads $this->names with an array of domains for this provider.
   */
  private function loadNames() {

    $aliases = array();
    foreach ($this->sites as $site) {
      if (in_array('shibboleth_available', $site->flags) || in_array('shibboleth_required', $site->flags)) {
        if (property_exists($site, 'aliases') && is_array($site->aliases)) {
          // This was observed not to be an array for the hostmaster
          // Probably has to do with the way Aegir creates the first site
          $aliases = array_merge($aliases, $site->aliases);
        }
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
    $this->names = $filtered;
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

}

