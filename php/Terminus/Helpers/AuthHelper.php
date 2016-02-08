<?php

namespace Terminus\Helpers;

use Terminus;
use Terminus\Exceptions\TerminusException;
use Terminus\Helpers\TerminusHelper;
use Terminus\Request;
use Terminus\Session;
use Terminus\Caches\TokensCache;
use Terminus\Utils;

class AuthHelper extends TerminusHelper {

  /**
   * @var Request
   */
  private $request;
  /**
   * @var TokensCache
   */
  private $tokens_cache;

  /**
   * Object constructor
   *
   * @param array $options Options and dependencies for this helper
   * @return AuthHelper
   */
  public function __construct(array $options = []) {
    $this->request      = new Request();
    $this->tokens_cache = new TokensCache();
    parent::__construct($options);
  }

  /**
   * Ensures the user is logged in or errs.
   *
   * @return bool Always true
   * @throws TerminusException
   */
  public function ensureLogin() {
    $session = Session::instance()->getData();
    if (!$this->loggedIn()) {
      if ($token = $this->getOnlySavedToken()) {
        $this->logInViaMachineToken($token);
      } else if (isset($_SERVER['TERMINUS_MACHINE_TOKEN'])
        && $token = $_SERVER['TERMINUS_MACHINE_TOKEN']
      ) {
        $this->logInViaMachineToken(compact('token'));
      } else if (isset($_SERVER['TERMINUS_USER'])
        && $email = $_SERVER['TERMINUS_USER']
      ) {
        $this->logInViaMachineToken(compact('email'));
      } else {
        $message  = 'You are not logged in. Run `auth login` to ';
        $message .= 'authenticate or `help auth login` for more info.';
        $this->log()->warning($message);
        exit(1);
      }
    }
    return true;
  }

  /**
   * Generates the URL string for where to create a machine token
   *
   * @return string
   */
  public function getMachineTokenCreationUrl() {
    $url = sprintf(
      '%s://%s:%s/machine-token/create?client=terminus&device=%s',
      TERMINUS_PROTOCOL,
      TERMINUS_HOST,
      TERMINUS_PORT,
      gethostname()
    );
    return $url;
  }

  /**
   * Gets the only saved token or returns false
   *
   * @return bool|string
   */
  public function getOnlySavedToken() {
    $emails = $this->tokens_cache->getAllSavedTokenEmails();
    if (count($emails) == 1) {
      $email = array_shift($emails);
      return $this->tokens_cache->findByEmail($email);
    }
    return false;
  }

  /**
   * Checks to see if the current user is logged in
   *
   * @return bool True if the user is logged in
   */
  public function loggedIn() {
    $session      = Session::instance()->getData();
    $is_logged_in = (
      isset($session->session)
      && (
        Utils\isTest()
        || ($session->session_expire_time >= time())
      )
    );
    return $is_logged_in;
  }

  /**
   * Execute the login based on a machine token
   *
   * @param string[] $args Elements as follow:
   *   string token Machine token to initiate login with
   *   string email Email address to locate token with
   * @return bool True if login succeeded
   * @throws TerminusException
   */
  public function logInViaMachineToken($args) {
    if (isset($args['token'])) {
      $token = $args['token'];
    } elseif (isset($args['email'])) {
      $token = $this->tokens_cache->findByEmail($args['email'])['token'];
      if (!$token) {
        throw new TerminusException(
          'No machine token for "{email}" found.',
          compact('email'),
          1
        );
      }
      $this->log()->info(
        'Found a machine token for "{email}".',
        ['email' => $args['email']]
      );
    }
    $options = array(
      'headers' => array('Content-type' => 'application/json'),
      'form_params'    => array(
        'machine_token' => $token,
        'client'        => 'terminus',
      ),
    );

    $this->log()->info('Logging in via machine token');
    try {
      $response = $this->request->request(
        'authorize',
        '',
        '',
        'POST',
        $options
      );
    } catch (\Exception $e) {
      throw new TerminusException(
        'The provided machine token is not valid.',
        [],
        1
      );
    }

    $data = $response['data'];
    $this->setInstanceData($response['data']);
    $user = Session::getUser();
    $user->fetch();
    $user_data = $user->serialize();
    $this->log()->info(
      'Logged in as {email}.',
      ['email' => $user_data['email']]
    );
    if (isset($args['token'])) {
      $this->tokens_cache->add(
        ['email' => $user_data['email'], 'token' => $token]
      );
    }
    return true;
  }

  /**
   * Execute the login via email/password
   *
   * @param string $email    Email address associated with a Pantheon account
   * @param string $password Password for the account
   * @return bool True if login succeeded
   * @throws TerminusException
   */
  public function logInViaUsernameAndPassword($email, $password) {
    if (!Terminus\Utils\isValidEmail($email)) {
      throw new TerminusException(
        '{email} is not a valid email address.',
        compact('email'),
        1
      );
    }

    $options  = array(
      'form_params' => array(
        'email' => $email,
        'password' => $password,
      ),
    );
    $response = $this->request->request('login', '', '', 'POST', $options);
    if ($response['status_code'] != '200') {
      throw new TerminusException(
        'Login unsuccessful for {email}',
        compact('email'),
        1
      );
    }
    $this->log()->info(
      'Logged in as {uuid}.',
      array('uuid' => $response['data']->user_id)
    );

    $this->setInstanceData($response['data']);
    return true;
  }

  /**
   * Checks to see whether the email has been set with a machine token
   *
   * @param string $email Email address to check for
   * @return bool
   */
  public function tokenExistsForEmail($email) {
    $file_exists = $this->tokens_cache->tokenExistsForEmail($email);
    return $file_exists;
  }

  /**
   * Saves the session data to a cookie
   *
   * @param \stdClass $data Session data to save
   * @return bool Always true
   */
  private function setInstanceData(\stdClass $data) {
    if (!isset($data->machine_token)) {
      $machine_token = (array)Session::instance()->get('machine_token');
    } else {
      $machine_token = $data->machine_token;
    }
    $session = array(
      'user_uuid'           => $data->user_id,
      'session'             => $data->session,
      'session_expire_time' => $data->expires_at,
    );
    if ($machine_token && is_string($machine_token)) {
      $session['machine_token'] = $machine_token;
    }
    Session::instance()->setData($session);
    return true;
  }

}
