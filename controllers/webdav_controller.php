<?php
/*
 * phtagr.
 * 
 * social photo gallery for your community.
 * 
 * Copyright (C) 2006-2010 Sebastian Felis, sebastian@phtagr.org
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

class WebdavController extends AppController
{
  var $components=array('RequestHandler', 'DigestAuth', 'FileCache');

  var $uses = array('User', 'MyFile', 'Media', 'Property', 'Lock');
  // Important to set the davroot in the Webdav Server
  var $name = 'webdav';

  /** @todo Remove configuration of debug */
  function beforeFilter() {
    // dont't call parent::beforeFilter(). Cookies (and sessions) are not
    // supported for WebDAV..

    Configure::write('debug', 0);
    if ($this->RequestHandler->isSSL()) {
      // If the connection is encrypted we can allow the basic authentication
      // schema. E.g. Anyclient 1.4 sends requests unasked with basic schema
      $this->DigestAuth->validSchemas = array('digest', 'basic');
    }
    $this->DigestAuth->authenticate();

    // Bind Properties and Locks to images persistently (only webdav is using it)
    $this->MyFile->bindModel(array(
      'hasMany' => array(
        'Property' => array('foreignKey' => 'file_id'),
        'Lock' => array('foreignKey' => 'file_id')
        )
      ));

    // Preload WebdavServer component which requires a running session
    $this->loadComponent('WebdavServer');
  }

  /** @todo Set webdav root to creator's root if user is guest */
  function index() {
    $this->requireRole(ROLE_GUEST);
    $this->layout = 'webdav';

    $user = $this->getUser();
    if (!$this->User->allowWebdav($user)) {
      Logger::err("WebDAV is not allowed to user '{$user['User']['username']}' (id {$user['User']['id']})");
      $this->redirect(null, 403);
    }

    $root = false;
    if ($user['User']['role'] == ROLE_GUEST) {
      $creator = $this->User->findById($user['User']['creator_id']);
      $root = $this->User->getRootDir($creator);
    } elseif ($user['User']['role'] >= ROLE_GUEST) {
      $root = $this->User->getRootDir($user);
    }
    if (!$root || !$this->WebdavServer->setFsRoot($root)) {
      Logger::err("Could not set fsroot: $root");
      $this->redirect(null, 401, true);
    }

    // start buffering
    ob_start();
    $this->WebdavServer->ServeRequest($_SERVER['REQUEST_URI']);
    while (@ob_end_flush());
    die();
  }
}
?>
