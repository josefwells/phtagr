<?php
/*
 * phtagr.
 * 
 * Multi-user image gallery.
 * 
 * Copyright (C) 2006-2009 Sebastian Felis, sebastian@phtagr.org
 * 
 * This program is free software; you can redistribute it and/or
 * modify it under the terms of the GNU General Public License
 * as published by the Free Software Foundation; version 2 of the 
 * License.
 * 
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 * 
 * You should have received a copy of the GNU General Public License
 * along with this program; if not, write to the Free Software
 * Foundation, Inc., 51 Franklin Street, Fifth Floor, Boston, MA  02110-1301, USA.
 */

class UsersController extends AppController
{
  var $components = array('RequestHandler', 'Cookie', 'Email', 'Captcha');
  var $uses = array('Option', 'Group'); 
  var $helpers = array('form', 'formular', 'number');
  var $paginate = array('limit' => 10, 'order' => array('User.username' => 'asc')); 
  var $menuItems = array();

  function beforeRender() {
    $this->_setMenu();
    parent::beforeRender();
  }

  function _getMenuItems() {
    $items = array();
    $items[] = array('text' => 'List users', 'link' => 'index');
    $items[] = array('text' => 'Add user', 'link' => 'add');
    $items[] = array('text' => 'Registration', 'link' => 'register');
    $items = am($items, $this->menuItems);
    return $items;
  }

  function _setMenu() {
    if ($this->hasRole(ROLE_SYSOP)) {
      $items = $this->requestAction('/system/getMenuItems');
      $me = '/admin/'.strtolower(Inflector::pluralize($this->name));
      foreach ($items as $index => $item) {
        if ($item['link'] == $me) {
          $item['submenu'] = array('items' => $this->_getMenuItems());
          $items[$index] = $item;
        }
      }
      $menu = array('items' => $items);
      $this->set('mainMenu', $menu);
    }
  }

  function __fromReadableSize($readable) {
    if (is_float($readable) || is_numeric($readable)) {
      return $readable;
    } elseif (preg_match_all('/^\s*(0|[1-9][0-9]*)(\.[0-9]+)?\s*([KkMmGg][Bb]?)?\s*$/', $readable, $matches, PREG_SET_ORDER)) {
      $matches = $matches[0];
      $size = (float)$matches[1];
      if (is_numeric($matches[2])) {
        $size += $matches[2];
      }
      if (is_string($matches[3])) {
        switch ($matches[3][0]) {
          case 'k':
          case 'K':
            $size = $size * 1024;
            break;
          case 'm':
          case 'M':
            $size = $size * 1024 * 1024;
            break;
          case 'g':
          case 'G':
            $size = $size * 1024 * 1024 * 1024;
            break;
          case 'T':
            $size = $size * 1024 * 1024 * 1024 * 1024;
            break;
          default:
            Logger::err("Unknown unit {$matches[3]}");
        }
      }
      if ($size < 0) {
        Logger::err("Size is negtive: $size");
        return 0;
      }
      return $size;
    } else {
      return 0;
    }
  }


  /** Checks the login of the user. If the session variable 'loginRedirect' is
   * set the user is forwarded to this given address on successful login. */
  function login() {
    $failedText = "Sorry. Wrong password or unknown username!";
    if (!empty($this->data) && !$this->RequestHandler->isPost()) {
      Logger::warn("Authentication failed: Request was not HTTP POST");
      $this->Session->setFlash($failedText);
      $this->data = null;
    }
    if (empty($this->data['User']['username']) xor empty($this->data['User']['password'])) {
      Logger::warn("Authentication failed: Username or password are not set");
      $this->Session->setFlash("Please enter username and password!");
      $this->data = null; 
    }

    if (!empty($this->data)) {
      $user = $this->User->findByUsername($this->data['User']['username']);

      if (!$user) {
        Logger::warn("Authentication failed: Unknown username '{$this->data['User']['username']}'!");
        $this->Session->setFlash($failedText);
      } elseif ($this->User->isExpired($user)) {
        Logger::warn("User account of '{$user['User']['username']}' (id {$user['User']['id']}) is expired!");
        $this->Session->setFlash("Sorry. Your account is expired!");
      } else {
        $user = $this->User->decrypt(&$user);
        if ($user['User']['password'] == $this->data['User']['password']) {
          $this->Session->renew();
          $this->Session->activate();
          if (!$this->Session->check('User.id') || $this->Session->read('User.id') != $user['User']['id']) {
            Logger::info("Start new session for '{$user['User']['username']}' (id {$user['User']['id']})");
            $this->User->writeSession($user, &$this->Session);

            // Save Cookie for 3 months
            $this->Cookie->write('user', $user['User']['id'], true, 92*24*3600);
            Logger::debug("Write authentication cookie for user '{$user['User']['username']}' (id {$user['User']['id']})");

            Logger::info("Successfull login of user '{$user['User']['username']}' (id {$user['User']['id']})");
            if ($this->Session->check('loginRedirect')) {
              $this->redirect($this->Session->read('loginRedirect'));
              $this->Session->delete('loginRedirect');
            } else {
              $this->redirect('/');
            }
          } else {
            Logger::err("Could not write session information of user '{$user['User']['username']}' ({$user['User']['id']})");
            $this->Session->setFlash("Sorry. Internal login procedure failed!");
          }
        } else {
          Logger::warn("Authentication failed: Incorrect password of username '{$this->data['User']['username']}'!");
          $this->Session->setFlash($failedText);
        }
      }
      unset($this->data['User']['password']);
    }
    $this->set('register', $this->Option->getValue($this->getUser(), 'user.register.enable', 0));
  }

  function logout() {
    $user = $this->getUser();
    Logger::info("Delete session for user id {$user['User']['id']}");

    $this->Session->destroy();

    $this->Cookie->name = 'phTagr';
    $this->Cookie->destroy();
 
    $this->redirect('/');
  }

  function profile($username) {
    $user = $this->User->findByUsername($username);

    $this->data = $user; 
  }
 
  function admin_index() {
    $this->requireRole(ROLE_SYSOP, array('loginRedirect' => '/admin/users'));

    $this->data = $this->paginate('User', array('User.role>='.ROLE_USER));
  }

  /** Ensure at least one admin exists
    @param id Current user
    @return True if at least one system operator exists */
  function _lastAdminCheck($id) {
    $userId = $this->getUserId();
    $userRole = $this->getUserRole();
    if ($userId == $id && $userRole == ROLE_ADMIN && $this->data['User']['role'] < ROLE_ADMIN) {
      $count = $this->User->find('count', array('conditions' => array('User.role >= '.ROLE_ADMIN)));
      if ($count == 1) {
        Logger::warn('Can not degrade last admin');
        $this->Session->setFlash('Can not degrade last admin');
        return false;
      }
    }
    return true;
  }

  function admin_edit($id) {
    $this->requireRole(ROLE_SYSOP, array('loginRedirect' => '/admin/users'));

    $id = intval($id);
    if (!empty($this->data) && $this->_lastAdminCheck($id)) {
      $this->data['User']['id'] = $id;

      $this->User->set($this->data);
      if ($this->User->save(null, true, array('password', 'email', 'expires', 'quota', 'firstname', 'lastname', 'role'))) {
        Logger::debug("Data of user {$this->data['User']['id']} was updated");
        $this->Session->setFlash('User data was updated');
      } else {
        Logger::err("Could not save user data");
        Logger::debug($this->User->validationErrors);
        $this->Session->setFlash('Could not be updated');
      }

      if (!empty($this->data['Option']['path']['fspath'])) {
        $fsroot = $this->data['Option']['path']['fspath'];
        $fsroot = Folder::slashTerm($fsroot);

        if (is_dir($fsroot))
          $this->Option->addValue('path.fsroot[]', $fsroot, $id);
      }
    }

    $this->data = $this->User->findById($id);
    unset($this->data['User']['password']);

    $this->set('fsroots', $this->Option->buildTree($this->data, 'path.fsroot'));
    $this->set('allowAdminRole', ($this->getUserRole() == ROLE_ADMIN) ? true : false);
    $this->menuItems[] = array(
      'text' => 'User '.$this->data['User']['username'], 
      'type' => 'text', 
      'submenu' => array(
        'items' => array(
          array(
            'text' => 'Edit', 
            'link' => 'edit/'.$id
            ),
          array(
            'text' => 'External Paths', 
            'link' => 'path/'.$id
            )
          )
        )
      );
  }

  function admin_path($id) {
    $this->requireRole(ROLE_SYSOP, array('loginRedirect' => '/admin/users'));

    $id = intval($id);
    if (!empty($this->data)) {
      $this->data['User']['id'] = $id;

      $this->User->set($this->data);

      if (!empty($this->data['Option']['path']['fspath'])) {
        $fsroot = $this->data['Option']['path']['fspath'];
        $fsroot = Folder::slashTerm($fsroot);

        if (is_dir($fsroot) && is_readable($fsroot)) {
          $this->Option->addValue('path.fsroot[]', $fsroot, $id);
          $this->Session->setFlash("Directory '$fsroot' was added");
          Logger::info("Add external directory '$fsroot' to user $id");
        } else {
          $this->Session->setFlash("Directory '$fsroot' could not be read");
          Logger::err("Directory '$fsroot' could not be read");
        }
      }
    }

    $this->data = $this->User->findById($id);
    unset($this->data['User']['password']);

    $this->set('fsroots', $this->Option->buildTree($this->data, 'path.fsroot'));

    $this->menuItems[] = array(
      'text' => 'User '.$this->data['User']['username'], 
      'type' => 'text', 
      'submenu' => array(
        'items' => array(
          array(
            'text' => 'Edit', 
            'link' => 'edit/'.$id
            ),
          array(
            'text' => 'External Paths', 
            'link' => 'path/'.$id
            )
          )
        )
      );
  }
 
  function admin_add() {
    $this->requireRole(ROLE_SYSOP, array('loginRedirect' => '/admin/users'));

    if (!empty($this->data)) {
      if ($this->User->hasAny(array('User.username' => $this->data['User']['username']))) {
        $this->Session->setFlash('Username already exists, please choose a different name!');
      } else {
        $this->data['User']['role'] = ROLE_USER;
        if ($this->User->save($this->data, true, array('username', 'password', 'role', 'email'))) {
          Logger::info("New user {$this->data['User']['username']} was created");
          $this->Session->setFlash('User was created');
          $this->redirect('/admin/users/edit/' . $this->User->id);
        } else {
          Logger::warn("Creation of user {$this->data['User']['username']} failed");
          $this->Session->setFlash('Could not create user!');
        }
      }
    }
  } 
  
  function admin_del($id) {
    $this->requireRole(ROLE_SYSOP, array('loginRedirect' => '/admin/users'));

    $id = intval($id);
    $user = $this->User->findById($id);
    if (!$user) {
      $this->Session->setFlash("Could not delete user: user not found!");
      $this->redirect('/admin/users/');
    } else {
      if ($user['User']['role'] == ROLE_ADMIN) {
        $count = $this->User->find('count', array('conditions' => array('User.role >=' => ROLE_ADMIN)));
        if ($count < 2) {
          $this->Session->setFlash("Could not delete last admin account");
          $this->redirect('/admin/users/');
        }
      }
      $this->User->del($id);
      Logger::notice("All data of user '{$user['User']['username']}' ($id) deleted");
      $this->Session->setFlash("User '{$user['User']['username']}' was deleted");
      $this->redirect('/admin/users/');
    }
  }

  function admin_delpath($id) {
    $this->requireRole(ROLE_SYSOP);

    $id = intval($id);
    $dirs = $this->params['pass'];
    unset($dirs[0]);
    $fsroot = implode(DS, $dirs);
    if (DS == '/') {
      $fsroot = '/'.$fsroot;
    }
    $fsroot = Folder::slashTerm($fsroot);
    
    $this->Option->delValue('path.fsroot[]', $fsroot, $id);
    Logger::info("Deleted external directory '$fsroot' from user $id");
    $this->Session->setFlash("Deleted external directory '$fsroot'");
    $this->redirect("path/$id");
  }

  function admin_register() {
    $this->requireRole(ROLE_SYSOP, array('loginRedirect' => '/admin/users'));

    if (!empty($this->data)) {
      if ($this->data['user']['register']['enable']) {
        $this->Option->setValue('user.register.enable', 1, 0);
      } else {
        $this->Option->setValue('user.register.enable', 0, 0);
      }
      $quota = $this->__fromReadableSize($this->data['user']['register']['quota']);
      $this->Option->setValue('user.register.quota', $quota, 0); 
      $this->Session->setFlash("Options saved!");
    }
    $this->data = $this->Option->getTree($this->getUserId());

    // add default values
    if (!isset($this->data['user']['register']['enable'])) {
      $this->data['user']['register']['enable'] = 0;
    }
    if (!isset($this->data['user']['register']['quota'])) {
      $this->data['user']['register']['quota'] = (float)100*1024*1024;
    }
    
  } 
 
  /** Password recovery */
  function password() {
    if (!empty($this->data)) {
      $user = $this->User->find(array(
          'username' => $this->data['User']['username'], 
          'email' => $this->data['User']['email']));
      if (empty($user)) {
        $this->Session->setFlash('No user with this email was found');
        Logger::warn(sprintf("No user '%s' with email %s was found",
            $this->data['User']['username'], $this->data['User']['email']));
      } else {
        $this->Email->to = sprintf("%s %s <%s>", 
            $user['User']['firstname'],
            $user['User']['lastname'],
            $user['User']['email']);
  
        $this->Email->subject = 'Password Request';
        $this->Email->replyTo = "noreply@{$_SERVER['SERVER_NAME']}";
        $this->Email->from = "phTagr <noreply@{$_SERVER['SERVER_NAME']}>";

        $this->Email->template = 'password';
        $user = $this->User->decrypt(&$user);
        $this->set('user', $user);

        if ($this->Email->send()) {
          Logger::info(sprintf("Sent password mail to user '%s' (id %d) with address %s",
            $user['User']['username'], 
            $user['User']['id'],
            $user['User']['email']));
          $this->Session->setFlash('Mail was sent!');
        } else {
          Logger::err(sprintf("Could not send password mail to user '%s' (id %d) with address '%s'",
            $user['User']['username'],
            $user['User']['id'],
            $user['User']['email']));
          $this->Session->setFlash('Mail could not be sent: unknown error!');
        }
      }
    }
  }

  function register() {
    if ($this->getUserRole() != ROLE_NOBODY) {
      $this->redirect('/');
    } elseif (!$this->getOption('user.register.enable', 0)) {
      Logger::verbose("User registration is disabled");
      $this->redirect('login');
    }

    if (!empty($this->data)) {
      if ($this->data['Captcha']['verification'] != $this->Session->read('user.register.captcha')) {
        $this->Session->setFlash('Captcha verification failed');
        Logger::verbose("Captcha verification failed");
      } elseif ($this->User->hasAny(array('User.username' => $this->data['User']['username']))) {
        $this->Session->setFlash('Username already exists, please choose different name!');
        Logger::info("Username already exists: {$this->data['User']['username']}");
      } else {
        $user = $this->User->create($this->data);
        if ($this->User->save($user['User'], true, array('id', 'username', 'password', 'email'))) {
          Logger::info("New user {$this->data['User']['username']} was created");
          $this->_initRegisteredUser($this->User->getLastInsertID());
        } else {
          Logger::err("Creation of user {$this->data['User']['username']} failed");
          $this->Session->setFlash('Could not create user');
        }
      }
    }
    unset($this->data['User']['password']);
    unset($this->data['User']['confirm']);
    unset($this->data['Captcha']['verification']);
  }

  function captcha() {
    if (!$this->getOption('user.register.enable', 0)) {
      $this->redirect(null, 404);
    }

    $this->Captcha->render('user.register.captcha');
  }

  function _initRegisteredUser($newUserId) {
    $user = $this->User->findById($newUserId);
    if (!$user) {
      Logger::err("Could not find user with ID $newUserId");
      return false;
    }
    $options = $this->Option->getTree(0);
    $user['User']['role'] = ROLE_USER;
    $user['User']['expires'] = date("Y-m-d H:i:s", time() - 3600);
    $user['User']['quota'] = $this->Option->getValue($options, 'user.register.quota', 0);
    if (!$this->User->save($user['User'], true, array('role', 'expires', 'quota'))) {
      Logger::err("Could not init user $newUserId");
      return false;
    }
    // set confirmation key
    $key = md5($newUserId.':'.$user['User']['username'].':'.$user['User']['password'].':'.$user['User']['expires']);
    $this->Option->setValue('user.register.key', $key, $newUserId);
    // send confimation email
    if (!$this->_sendConfirmationEmail($user, $key)) {
      $this->Session->setFlash("Could not send the confirmation email. Please contact the admin.");
      return false;
    }
    $this->Session->setFlash("A confirmation email was sent to your email address");
    $this->redirect("/users/confirm");
  }

  function confirm($key = false) {
    if ($this->getUserRole() != ROLE_NOBODY) {
      $this->redirect('/');
    } elseif (!$this->getOption('user.register.enable', 0)) {
      Logger::verbose("User registration is disabled");
      $this->redirect(null, 404);
    }

    if (!$key && !empty($this->data)) {
      // check user input
      if (empty($this->data['User']['key'])) {
        $this->Session->setFlash("Please enter the confirmation key");
      } else { 
        $key = $this->data['User']['key']; 
      }
    }

    if ($key) {
      $this->_checkConfirmation($key);
    }
  }

  /** Verifies the confirmation key and activates the new user account 
    @param key Account confirmation key */
  function _checkConfirmation($key) {
    // check key. Option [belongsTo] User: The user is bound to option
    $user = $this->Option->find(array("Option.value" => $key));
    if (!$user) {
      Logger::trace("Could not find confirmation key");
      $this->Session->setFlash("Could not find confirmation key");
      return false;
    } 

    if (!isset($user['User']['id'])) {
      Logger::err("Could not find the user for register confirmation");
      $this->Session->setFlash("Internal error occured");
      return false;
    }
    
    // check expiration (14 days+1h). After this time, the account will be
    // deleted
    $now = time();
    $expires = strtotime($user['User']['expires']);
    if ($now - $expires > (14 * 24 * 3600 + 3600)) {
      $this->Session->setFlash("Could not find confirmation key");
      Logger::err("Registration confirmation is expired.");
      $this->User->delete($user['User']['id']);
      Logger::info("Deleted user from expired registration");
      return false;
    }

    // activate user account (disabling the expire date)
    $user['User']['expires'] = null;
    if (!$this->User->save($user['User'], true, array('expires'))) {
      Logger::err("Could not update expires of user {$user['User']['id']}");
      return false;
    }
    // send email to user and notify the sysops
    $this->_sendNewAccountEmail($user);
    $this->_sendNewAccountNotifiactionEmail($user);

    // delete confirmation key
    $this->Option->delete($user['Option']['id']);

    // login the user automatically 
    $this->User->writeSession($user, &$this->Session);
    $this->redirect('/options/profile');
  }

  /** Send the confirmation email to the new user
    @param user User model data
    @param key Confirmation key to activate the account */
  function _sendConfirmationEmail($user, $key) {
    $this->Email->to = $user['User']['email'];

    $this->Email->subject = '[phtagr] Account confirmation: '.$user['User']['username'];
    $this->Email->replyTo = "noreply@{$_SERVER['SERVER_NAME']}";
    $this->Email->from = "{$_SERVER['SERVER_NAME']} <noreply@{$_SERVER['SERVER_NAME']}>";

    $this->Email->template = 'new_account_confirmation';
    $this->set('user', $user);
    $this->set('key', $key);

    if (!$this->Email->send()) {
      Logger::err("Could not send account confirmation mail to {$user['User']['email']} for new user {$user['User']['username']}");
      return false;
    } else {
      Logger::info("Account confirmation mail send to {$user['User']['email']} for new user {$user['User']['username']}");
      return true;
    }
  }

  /** Send an email for the new account to the new user 
    @param user User model data */
  function _sendNewAccountEmail($user) {
    $this->Email->to = $user['User']['email'];

    $this->Email->subject = '[phtagr] Welcome '.$user['User']['username'];
    $this->Email->replyTo = "noreply@{$_SERVER['SERVER_NAME']}";
    $this->Email->from = "{$_SERVER['SERVER_NAME']} <noreply@{$_SERVER['SERVER_NAME']}>";

    $this->Email->template = 'new_account';
    $this->set('user', $user);

    if (!$this->Email->send()) {
      Logger::err("Could not send new account mail to {$user['User']['email']} for new user {$user['User']['username']}");
      return false;
    }
    Logger::info("New account mail send to {$user['User']['email']} for new user {$user['User']['username']}");
    return true;
  }

  /** Send a notification email to all system operators (and admins) of the new
   * account
    @param user User model data (of the new user) */
  function _sendNewAccountNotifiactionEmail($user) {
    $sysOps = $this->User->findAll("User.role >= ".ROLE_SYSOP);
    if (!$sysOps) {
      Logger::err("Could not find system operators");
      return false;
    }
    $emails = Set::combine($sysOps, '{n}.User.id', array('{0} <{1}>', '{n}.User.username', '{n}.User.email'));

    $this->Email->to = array_pop($emails);
    $this->Email->bcc = $emails;

    $this->Email->subject = '[phtagr] New account notification: '.$user['User']['username'];
    $this->Email->replyTo = "noreply@{$_SERVER['SERVER_NAME']}";
    $this->Email->from = "{$_SERVER['SERVER_NAME']} <noreply@{$_SERVER['SERVER_NAME']}>";

    $this->Email->template = 'new_account_notification';
    $this->set('user', $user);

    if (!$this->Email->send()) {
      Logger::err("Could not send new account notification email to system operators ".implode(', ', Set::combine($sysOps, '{n}.User.id', '{n}.User.username')));
      return false;
    } 
    Logger::info("New account notification email send to system operators ".implode(', ', Set::combine($sysOps, '{n}.User.id', '{n}.User.username')));
    return true;
  }

}
?>
