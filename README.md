# Auth for people who hate the Auth component

Authsome is a CakePHP 1.2 plugin that makes authentication a pleasure to work with by following a few simple rules:

**Assume nothing:** Authsome requires that you have some kind of user model, but that's it. It doesn't care if you use a database, passwords or religious ceremonies for verifying your member logins.

**Touch nothing:** Authsome does not interact with your application at all. No login redirects, no permissions checks, nothing. You never have to worry about the underlaying magic, it will never get into your way.

**Always available:** Authsome is there for you when you need it. You can do stuff like `Authsome::get('id')` from anywhere in your project. If you have MVC OCD, you can also use Authsome as a regular component: `$this->Authsome->get('id')`

## Installation

Copy the contents of this directory into plugins/authsome:

	cd my_cake_app
	git clone git://github.com/felixge/cakephp-authsome.git plugins/authsome

Load authsome in your AppController and specify the name of your user model:

	class AppController extends Controller {
		public $components = array(
			'Authsome.Authsome' => array(
				'model' => 'User'
			)
		);
	}

Implement authsomeLogin in your user model (must return a non-null value):

	class User extends AppModel{
		public function authsomeLogin($type, $credentials = array()) {
			switch ($type) {
				case 'guest':
					// You can return any non-null value here, if you don't
					// have a guest account, just return an empty array
					return array('it' => 'works');
				case 'credentials':
					$password = Authsome::hash($credentials['password']);

					// This is the logic for validating the login
					$conditions = array(
						'User.email' => $credentials['email'],
						'User.password' => $password,
					);
					break;
				default:
					return null;
			}

			return $this->find('first', compact('conditions'));
		}
	}

Almost done! Check if you did everything right so far by putting this in one of your controllers:

	$guest = Authsome::get();
	debug($guest);

If this returns `Array([it] => works)`, you can go ahead and implement a simple login function:

	class UsersController extends AppController{
		public function login() {
			if (empty($this->data)) {
				return;
			}

			$user = Authsome::login($this->data['User']);

			if (!$user) {
				$this->Session->setFlash('Unknown user or wrong password');
				return;
			}

			$user = Authsome::get();
			debug($user);
		}
	}

And add a app/views/users/login.ctp file like this:

	<h2><?php echo $this->pageTitle = 'Login'; ?></h2>
	<?php
	echo $form->create('User', array('action' => $this->action));
	echo $form->input('email', array('label' => 'Email'));
	echo $form->input('password', array('label' => "Password"));
	echo $form->submit('Login');
	echo $form->end();
	?>


The array passed into `Authsome::login()` gets passed directly to your `authsomeLogin` function, so you really pass any kind of credentials. You can even come up with your own authentication types by doing `Authsome::login('voodoo_auth', $chickenBones)`.

## Cookies

Any login created by `Authsome::login()` will only last as long as your CakePHP session itself. However, you might want to offer one of those nifty "Remember me for 2 weeks" buttons. `Authsome::persist()` comes to rescue!

First of all change your login action like this:

	public function login() {
		if (empty($this->data)) {
			return;
		}

		$user = Authsome::login($this->data['User']);

		if (!$user) {
			$this->Session->setFlash('Unknown user or wrong password');
			return;
		}

		$remember = (!empty($this->data['User']['remember']));
		if ($remember) {
			Authsome::persist('2 weeks');
		}
	}

Also add a checkbox like this to your form:

	echo $form->input('remember', array(
		'label' => "Remember me for 2 weeks",
		'type' => "checkbox"
	));

Authsome itself does not care how you manage your cookie login tokens for auth persistence, but I highly recommend following [Charles' Receipe][1] for this. Charles recommends to create a table that maps `user_id`s and login tokens, here is what I use:

[1]: http://fishbowl.pastiche.org/2004/01/19/persistent_login_cookie_best_practice/

	CREATE TABLE `login_tokens` (
	  `id` int(11) NOT NULL auto_increment,
	  `user_id` int(11) NOT NULL,
	  `token` char(32) NOT NULL,
	  `duration` varchar(32) NOT NULL,
	  `used` tinyint(1) NOT NULL default '0',
	  `created` datetime NOT NULL,
	  `expires` datetime NOT NULL,
	  PRIMARY KEY  (`id`)
	) ENGINE=MyISAM DEFAULT CHARSET=utf8

Don't forget to create an empty model in app/models/login_token.php for this:

	class LoginToken extends AppModel{
	}

Next you'll need to implement `authsomePersist` in your user model, which creates and stores a unique login token when `Authsome::persist()` is called:

	public $hasMany = array('LoginToken');

	public function authsomePersist($user, $duration) {
		$token = md5(uniqid(mt_rand(), true));
		$userId = $user['User']['id'];

		$this->LoginToken->create(array(
			'user_id' => $userId,
			'token' => $token,
			'duration' => $duration,
			'expires' => date('Y-m-d H:i:s', strtotime($duration)),
		));
		$this->LoginToken->save();

		return "${token}:${userId}";
	}

So far so good. If you are still on track, you should now be able to see new records showing up in your `login_tokens` table if you log in with the remember checkbox checked.

If so, proceed to the next step and add the `'cookie'` login `$type` to your authsomeLogin function:

	public function authsomeLogin($type, $credentials = array()) {
		switch ($type) {
			case 'guest':
				// You can return any non-null value here, if you don't
				// have a guest account, just return an empty array
				return array('it' => 'works');
			case 'credentials':
				$password = Authsome::hash($credentials['password']);

				// This is the logic for validating the login
				$conditions = array(
					'User.email' => $credentials['email'],
					'User.password' => $password,
				);
				break;
			case 'cookie':
				list($token, $userId) = split(':', $credentials['token']);
				$duration = $credentials['duration'];

				$loginToken = $this->LoginToken->find('first', array(
					'conditions' => array(
						'user_id' => $userId,
						'token' => $token,
						'duration' => $duration,
						'used' => false,
						'expires <=' => date('Y-m-d H:i:s', strtotime($duration)),
					),
					'contain' => false
				));

				if (!$loginToken) {
					return false;
				}

				$loginToken['LoginToken']['used'] = true;
				$this->LoginToken->save($loginToken);

				$conditions = array(
					'User.id' => $loginToken['LoginToken']['user_id']
				);
				break;
			default:
				return null;
		}

		return $this->find('first', compact('conditions'));
	}

Let's go over this real quick. First we are checking the db for a matching token. If none is found, we return false. If we find a valid token, we invalidate it and set the conditions for finding the user that belongs to the token.

Pretty simple! You could also do this entirely different. For example you could skip having a `login_tokens` table all together and instead give out tokens that are signed with a secret and a timestamp. However, the drawback with those tokens is that they could be used multiple times which makes cookie theft a more severe problem.

**Security Advisory:** You should require users to re-authenticate using an alternative login method in case of the following:

* Changing the user's password
* Changing the user's email address (especially if email-based password recovery is used)
* Any access to the user's address, payment details or financial information
* Any ability to make a purchase

This can easily be done by tweaking the end of your authsomeLogin function like this:

	$user = $this->find('first', compact('conditions'));
	if (!$user) {
		return false;
	}
	$user['User']['loginType'] = $type;
	return $user;

Then deny access to any of the functionality mentioned above like this:

	if (Authsome::get('loginType') === 'cookie') {
		Authsome::logout();
		$this->redirect(array(
			'controller' => 'users',
			'action' => 'login',
		))
	}

## Documentation

### AuthsomeComponent::initialize($controller, $settings)

Initializes the AuthsomeComponent with the given settings. This method is called for you when including Authsome in your AppController:

	public $components = array(
		'Authsome.Authsome' => array(
			'model' => 'User'
		)
	);

Available `$settings` and their defaults:

	'model' => 'User',
	// Those all default to $settings['model'] if not set explicitly
	'configureKey' => null,
	'sessionKey' => null,
	'cookieKey' => null,

### AuthsomeComponent::get($field = null)

Returns the current user record. If `$field` is given, the records sub-field for the main model is extracted. The following two calls are identical:

	$this->Authsome->get('id');
	$this->Authsome->get('User.id');

However, you could can also access any associations you may habe returned from your user models `authsomeLogin` function:

	$this->Authsome->get('Role.name');

### AuthsomeComponent::login($type = 'credentials', $credentials = null)

Passes the given `$type` and `$credentials` to your user model `authsomeLogin` function. Returns false on failure, or the user record on success.

If you skip the `$type` parameter, the default will be `'credentials'`. This means the following two calls are identical:

	$user = $this->Authsome->login('credentials', $this->data);
	$user = $this->Authsome->login($this->data);

### AuthsomeComponent::logout()

Destroys the current authsome session and also deletes any authsome cookies.

### AuthsomeComponent::persist($duration = '2 weeks')

Calls the user models `authsomePersist` function to get a login token and stores it in a cookie. `$duration` must be a relative time string that can be parsed by `strtotime()` and must not be an absolute date or timestamp.

When performing a cookie login, authsome will automatically renew the login cookie for the given `$duration` again.

### AuthsomeComponent::hash($passwords)

Takes the given `$passwords` and returns the sha1 hash for it using core.php's `'Security.salt'` setting. The following two lines are identical:

	$hashedPw = $this->Authsome->hash('foobar');
	$hashedPw = Security::hash('foobar', 'sha1', true);

This is a convenience function. It is not used by Authsome internally, you are free to use any password hashing schema you desire.

### Static convenience functions

The following static shortcuts exist for your convenience:

	Authsome::get()
	Authsome::login()
	Authsome::logout()
	Authsome::persist()
	Authsome::hash()

They are identical to calling the AuthsomeComponent in your controller, but allow you to access Authsome anywhere in your app (models, views, etc.). If you suffer from MVC OCD, do not use these functions.

## Under the hood

Authsome builds on a fairly simple logic. The first time you call `Authsome::get()`, it tries to find out who the active user it. This is done as follows:

1. Check if Configure::read($this->settings['configureKey']) for a user record
2. Check $this->Session->read($this->settings['sessionKey']) for a user record
3. Check $this->Cookie->read($this->settings['cookieKey']) for a token

If all 3 of those checks do not produce a valid user record, authsome calls the user models `authsomeLogin('guest')` function and takes the record returned from that. If even that fails, authsome will throw an exception and bring your app to a crashing halt.

## License

Authsome is licensed under the MIT license.

## Sponsors

The initial development of Authsome was paid for by [ThreeLeaf Creative](http://threeleaf.tv/), the makers of a fantastic CakePHP CMS system.

Authsome is developed by [Debuggable Ltd](http://debuggable.com/). Get in touch if you need help making your next project an authsome one!