<?php
// @todo
// - Handle exceptions thrown from this component properly
// - Restore user information when the active user changes his profile
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

		if (empty($field) || is_null($user)) {
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

		if ($user === false) {
			return false;
		}

		Configure::write($this->settings['configureKey'], $user);
		$this->Session->write($this->settings['sessionKey'], $user);
		return $user;
	}

	public function logout() {
		Configure::write($this->settings['configureKey'], array());
		$this->Session->write($this->settings['sessionKey'], array());

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

	public function hash() {
		$args = func_get_args();
		return call_user_func_array(
			array('Authsome', __FUNCTION__),
			$args
		);
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
		if (!$user) {
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
		$this->Cookie->del($this->settings['cookieKey']);

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

	public static function get() {
		$args = func_get_args();
		return call_user_func_array(
			array(self::instance(), __FUNCTION__),
			$args
		);
	}

	public static function login() {
		$args = func_get_args();
		return call_user_func_array(
			array(self::instance(), __FUNCTION__),
			$args
		);
	}

	public static function logout() {
		$args = func_get_args();
		return call_user_func_array(
			array(self::instance(), __FUNCTION__),
			$args
		);
	}

	public static function persist() {
		$args = func_get_args();
		return call_user_func_array(
			array(self::instance(), __FUNCTION__),
			$args
		);
	}

	public static function hash($password, $method = 'sha1', $salt = true) {
		return Security::hash($password, $method, $salt);
	}
}
?>