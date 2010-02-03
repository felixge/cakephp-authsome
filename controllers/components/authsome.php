<?php
/**
 * Copyright (c) 2009 Debuggable Ltd (debuggable.com)
 * 
 * Permission is hereby granted, free of charge, to any person obtaining a copy
 * of this software and associated documentation files (the "Software"), to deal
 * in the Software without restriction, including without limitation the rights
 * to use, copy, modify, merge, publish, distribute, sublicense, and/or sell
 * copies of the Software, and to permit persons to whom the Software is
 * furnished to do so, subject to the following conditions:
 * 
 * The above copyright notice and this permission notice shall be included in
 * all copies or substantial portions of the Software.
 * 
 * THE SOFTWARE IS PROVIDED "AS IS", WITHOUT WARRANTY OF ANY KIND, EXPRESS OR
 * IMPLIED, INCLUDING BUT NOT LIMITED TO THE WARRANTIES OF MERCHANTABILITY,
 * FITNESS FOR A PARTICULAR PURPOSE AND NONINFRINGEMENT. IN NO EVENT SHALL THE
 * AUTHORS OR COPYRIGHT HOLDERS BE LIABLE FOR ANY CLAIM, DAMAGES OR OTHER
 * LIABILITY, WHETHER IN AN ACTION OF CONTRACT, TORT OR OTHERWISE, ARISING FROM,
 * OUT OF OR IN CONNECTION WITH THE SOFTWARE OR THE USE OR OTHER DEALINGS IN
 * THE SOFTWARE.
 */
class AuthsomeComponent extends Object{
	public $components = array(
		'Session',
		'Cookie',
		'RequestHandler',
	);

	public $settings = array(
		'model' => 'User',
		'configureKey' => null,
		'sessionKey' => null,
		'cookieKey' => null,
	);

	private $__userModel;

	public function initialize($controller, $settings = array()) {
		Authsome::instance($this);
		$this->settings = Set::merge($this->settings, $settings);

		// Use the model name as the key everywhere by default
		$keys = array('configure', 'session', 'cookie');
		foreach ($keys as $prefix) {
			$key = $prefix.'Key';
			if (empty($this->settings[$key])) {
				$this->settings[$key] = $this->settings['model'];
			}
		}
	}

	public function get($field = null) {
		$user = $this->__getActiveUser();

		if (empty($field)) {
			return $user;
		}

		if (strpos($field, '.') === false) {
			if (in_array($field, array_keys($user))) {
				return $user[$field];
			}
			$field = $this->settings['model'].'.'.$field;
		}

		return Set::extract($user, $field);
	}

	public function login($type = 'credentials', $credentials = null) {
		$userModel = $this->__getUserModel();

		$args = func_get_args();
		if (!method_exists($userModel, 'authsomeLogin')) {
			throw new Exception(
				$userModel->alias.'::authsomeLogin() is not implemented!'
			);
		}

		if (!is_string($type) && is_null($credentials)) {
			$credentials = $type;
			$type = 'credentials';
		}

		$user = $userModel->authsomeLogin($type, $credentials);

		Configure::write($this->settings['configureKey'], $user);
		$this->Session->write($this->settings['sessionKey'], $user);
		return $user;
	}

	public function logout() {
		Configure::write($this->settings['configureKey'], array());
		$this->Session->write($this->settings['sessionKey'], array());
		$this->Cookie->write($this->settings['cookieKey'], '');

		return true;
	}

	public function persist($duration = '2 weeks') {
		$userModel = $this->__getUserModel();

		if (!method_exists($userModel, 'authsomePersist')) {
			throw new Exception(
				$userModel->alias.'::authsomePersist() is not implemented!'
			);
		}

		$token = $userModel->authsomePersist(Authsome::get(), $duration);
		$token = $token.':'.$duration;

		return $this->Cookie->write(
			$this->settings['cookieKey'],
			$token,
			true, // encrypt = true
			$duration
		);
	}

	public function hash($password) {
		return Authsome::hash($password);
	}

	private function __getUserModel() {
		if ($this->__userModel) {
			return $this->__userModel;
		}

		return $this->__userModel = ClassRegistry::init(
			$this->settings['model']
		);
	}

	private function __getActiveUser() {
		$user = Configure::read($this->settings['configureKey']);
		if (!empty($user)) {
			return $user;
		}

		$this->__useSession() ||
		$this->__useCookieToken() ||
		$this->__useGuestAccount();

		$user = Configure::read($this->settings['configureKey']);
		if (is_null($user)) {
			throw new Exception(
				'Unable to initilize user'
			);
		}

		return $user;
	}

	private function __useSession() {
		$user = $this->Session->read($this->settings['sessionKey']);
		if (!$user) {
			return false;
		}

		Configure::write($this->settings['configureKey'], $user);
		return true;
	}

	private function __useCookieToken() {
		$token = $this->Cookie->read($this->settings['cookieKey']);
		if (!$token) {
			return false;
		}

		// Extract the duration appendix from the token
		$tokenParts = split(':', $token);
		$duration = array_pop($tokenParts);
		$token = join(':', $tokenParts);

		$user = $this->login('cookie', compact('token', 'duration'));

		// Delete the cookie once its been used
		$this->Cookie->delete($this->settings['cookieKey']);

		if (!$user) {
			return;
		}

		$this->persist($duration);

		return (bool)$user;
	}

	private function __useGuestAccount() {
		return $this->login('guest');
	}
}

// Static Authsomeness
class Authsome{
	static function instance($setInstance = null) {
		static $instance;

		if ($setInstance) {
			$instance = $setInstance;
		}

		if (!$instance) {
			throw new Exception(
				'AuthsomeComponent not initialized properly!'
			);
		}

		return $instance;
	}

	public static function get($field = null) {
		return self::instance()->get($field);
	}

	public static function login($type = 'credentials', $credentials = null) {
		return self::instance()->login($type, $credentials);
	}

	public static function logout() {
		return self::instance()->logout();
	}

	public static function persist($duration = '2 weeks') {
		return self::instance()->persist($duration);
	}

	public static function hash($password, $method = 'sha1', $salt = true) {
		return Security::hash($password, $method, $salt);
	}
}
?>