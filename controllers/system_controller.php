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
class SystemController extends AppController {

  var $name = 'System';
  var $helpers = array('Form');
  var $uses = array('Option');
  var $subMenu = array();

  function beforeFilter() {
    parent::beforeFilter();
    $this->requireRole(ROLE_SYSOP, array('redirect' => '/'));

    $this->subMenu = array(
      'index' => __("General", true),
      'external' => __("External Programs", true),
      'map' => __("Map Settings", true),
      'upgrade' => __("Database Upgrade", true),
      'deleteUnusedMetaData' => __("Delete Unused Metadata", true)
      );
  }

  function beforeRender() {
    $this->layout = 'backend';
    parent::beforeRender();
  }

  function _set($userId, $path, $data) {
    $value = Set::extract($data, $path);
    $this->Option->setValue($path, $value, $userId);
  }

  function index() {
    if (isset($this->data)) {
      $this->_set(0, 'general.title', $this->data);
      $this->_set(0, 'general.subtitle', $this->data);
    }
    $this->data = $this->Option->getTree(0);
  }

  function external() {
    if (!empty($this->data)) {
      // TODO check valid acl
      $this->_set(0, 'bin.exiftool', $this->data);
      $this->_set(0, 'bin.convert', $this->data);
      $this->_set(0, 'bin.ffmpeg', $this->data);
      $this->_set(0, 'bin.flvtool2', $this->data);
      // debug
      $this->set('commit', $this->data);
      $this->Session->setFlash("Settings saved");
    }
    $tree = $this->Option->getTree(0);
    $this->data = $tree;
  }

  function map() {
    if (!empty($this->data)) {
      $this->_set(0, 'google.map.key', $this->data);
      // debug
      $this->set('commit', $this->data);
      $this->Session->setFlash("Settings saved");
    }
    $tree = $this->Option->getTree(0);
    $this->data = $tree;
  }
  
  /** Database upgrade via the Migraions plugin */
  function upgrade($action = '') {
    App::import('Lib', 'Migrations.MigrationVersion');
    $Migration = new MigrationVersion(array('connection' => 'default'));
    if (empty($Migration)) {
      Logger::err("Could not load class Migrations.MigrationVersion");
      $this->redirect(false, 505);
    }
    $currentVersion = $Migration->getVersion('app');
    // Fallback of older databases: Add fist version if not exists
    if ($currentVersion == 0) {
      $Migration->setVersion(1, 'app');
    }
    $migrationVersion = max(array_keys($Migration->getMapping('app')));
    if ($action == 'run' && $currentVersion < $migrationVersion) {
      if (!$Migration->run(array('type' => 'app', 'direction' => 'up'))) {
        $this->Session->setFlash(__("Database migration failed. Please see the log files for errors.", true));
        Logger::error("Could not run migration");
      } else {
        Logger::info("Upgraded database from version $currentVersion to " . max(array_keys($Migration->getMapping('app'))));
        $this->Session->setFlash(__("The database migration was successful.", true));
      }
    }
    $this->set('currentVersion', $Migration->getVersion('app'));
    $mappings = $Migration->getMapping('app');
    $this->set('maxVersion', max(array_keys($mappings)));

    $newMappingNames = array();
    foreach ($mappings as $version => $mapping) {
      if ($version > $currentVersion) {
        $newMappingNames[] = $mapping['name'];
      }
    }
    $this->set('newMappingNames', $newMappingNames);
  }

  function deleteUnusedMetaData($delete = '') {
    App::import('Model', array('Tag', 'Category', 'Location'));

    $this->Tag =& new Tag();
    $this->Tag->bindModel(array('hasAndBelongsToMany' => array('Media')), false);
    $this->Tag->Behaviors->attach('DeleteUnused', array('relatedHabtm' => 'Media'));
    $unusedTagCount = count($this->Tag->findAllUnused());

    $this->Category =& new Category();
    $this->Category->bindModel(array('hasAndBelongsToMany' => array('Media')), false);
    $this->Category->Behaviors->attach('DeleteUnused', array('relatedHabtm' => 'Media'));
    $unusedCategoryCount = count($this->Category->findAllUnused());

    $this->Location =& new Location();
    $this->Location->bindModel(array('hasAndBelongsToMany' => array('Media')), false);
    $this->Location->Behaviors->attach('DeleteUnused', array('relatedHabtm' => 'Media'));
    $unusedLocationCount = count($this->Location->findAllUnused());

    if ($delete == 'delete') {
      $this->Tag->deleteAllUnused();
      $this->Location->deleteAllUnused();
      $this->Category->deleteAllUnused();
      $this->Session->setFlash(__("All unused meta data are deleted", true));
    }
    $this->data = compact('unusedTagCount', 'unusedCategoryCount', 'unusedLocationCount');
  }
}
?>
