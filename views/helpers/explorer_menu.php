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

class ExplorerMenuHelper extends AppHelper
{
  var $helpers = array('Html', 'Search', 'Breadcrumb', 'Menu', 'Piclens', 'Form');

  var $_id;

  /** Count the association
    @param association name
    @return Array of accociation name as key and their count as value */
  function _countAssociation($association) {
    $result = array();
    if (isset($this->data['Media'])) {
      $data = array($this->data);
    } else {
      $data =& $this->data;
    }
    if (!$data) {
      return false;
    }
    foreach ($data as $media) {
      $values = Set::extract("/$association/name", $media);
      foreach ($values as $value) {
        if (!isset($result[$value])) {
          $result[$value] = 1;
        } else {
          $result[$value]++;
        }
      }
    }
    arsort($result);
    return $result;
  }

  function _getSlideshowItem() {
    $id = 'menu-slideshow';

    $extra = " <div class=\"actionlist\" id=\"$id\">";
    $link = "javascript:startSlideshow('high');";
    $icon = $this->Html->image('icons/star.png', array('alt' => 'high', 'title' => __("Show media in high quality (if available)", true)));
    $extra .= $this->Html->link($icon, $link, array('escape' => false));
    $extra .= "</div>";

    $text = $this->Html->link(__('Start Slideshow', true), "javascript:startSlideshow('');", array('escape' => false));
    $item = array(
      'text' => $text.$extra,
      'type' => 'multi',
      'onmouseover' => "toggleVisibility('$id', 'inline');",
      'onmouseout' => "toggleVisibility('$id', 'inline');"
      );
    return $item;
  }

  function _getAssociationExtra($name, $value, $id) {
    $out = " <div class=\"actionlist\" id=\"$id\">";
    $crumbs = $this->Breadcrumb->filterCrumbs($this->params['crumbs']);

    $addLink = $this->Breadcrumb->crumbUrl($this->Breadcrumb->replace($crumbs, array($name, '-' . $value), $value));
    $addIcon = $this->Html->image('icons/add.png', array('alt' => '+', 'title' => "Include $name $value"));
    $out .= $this->Html->link($addIcon, $addLink, array('escape' => false));

    $delLink = $this->Breadcrumb->crumbUrl($this->Breadcrumb->replace($crumbs, array($name, $value), '-' . $value));
    $delIcon = $this->Html->image('icons/delete.png', array('alt' => '-', 'title' => "Exclude $name $value"));
    $out .= $this->Html->link($delIcon, $delLink, array('escape' => false));

    if ($this->action == 'user') {
      $worldLink = "/explorer/$name/$value";
      $worldIcon = $this->Html->image('icons/world.png', array('alt' => '-', 'title' => "View all media with $name $value"));
      $out .= $this->Html->link($worldIcon, $worldLink, array('escape' => false));
    }

    $out .= "</div>";
    return $out;
  }

  function _getAssociationSubMenu($association) {
    $counts = $this->_countAssociation(Inflector::camelize($association));
    if (!$counts) {
      return false;
    }

    $subMenu = array();
    $base = '/explorer';
    if ($this->action == 'user') {
      $base .= '/user/'.$this->params['pass'][0];
    }
    foreach($counts as $name => $count) {
      $id = "item-".$this->_id++;
      $link = $this->Html->link($name, "$base/$association/$name");
      $extra = $this->_getAssociationExtra($association, $name, $id);
      $subMenu[] = array(
        'text' => "$link ($count) $extra",
        'type' => 'multi',
        'onmouseover' => "toggleVisibility('$id', 'inline');",
        'onmouseout' => "toggleVisibility('$id', 'inline');"
        );
    }
    return $subMenu;
  }

  function _getOrderItem() {
    $link = $this->Search->getUri(false, array('sort' => 'date'), 'page');
    $out = $this->Html->link(__("Order", true), $link);

    $id = 'order-item';
    $out .= " <div class=\"actionlist\" id=\"$id\">";
    $crumbs = $this->Breadcrumb->filterCrumbs($this->params['crumbs']);
    
    $icon = $this->Html->image('icons/date_previous.png', array('alt' => __('date asc', true), 'title' => __("Show oldest first", true)));
    $link = $this->Breadcrumb->crumbUrl($this->Breadcrumb->replace($crumbs, 'sort', '-date'));
    $out .= $this->Html->link($icon, $link, array('escape' => false));
    
    $icon = $this->Html->image('icons/add.png', array('alt' => __('newest', true), 'title' => __("Show newest first", true)));
    $link = $this->Breadcrumb->crumbUrl($this->Breadcrumb->replace($crumbs, 'sort', 'newest'));
    $out .= $this->Html->link($icon, $link, array('escape' => false));
    
    $icon = $this->Html->image('icons/heart.png', array('alt' => __('pouplarity', true), 'title' => __("Show popular first", true)));
    $link = $this->Breadcrumb->crumbUrl($this->Breadcrumb->replace($crumbs, 'sort', 'popularity'));
    $out .= $this->Html->link($icon, $link, array('escape' => false));
    
    $icon = $this->Html->image('icons/images.png', array('alt' => __('random', true), 'title' => __("Show random order", true)));
    $link = $this->Breadcrumb->crumbUrl($this->Breadcrumb->replace($crumbs, 'sort', 'random'));
    $out .= $this->Html->link($icon, $link, array('escape' => false));
    
    $icon = $this->Html->image('icons/pencil.png', array('alt' => __('Changes', true), 'title' => __("Show changes first", true)));
    $link = $this->Breadcrumb->crumbUrl($this->Breadcrumb->replace($crumbs, 'sort', 'changes'));
    $out .= $this->Html->link($icon, $link, array('escape' => false));
    
    $icon = $this->Html->image('icons/eye.png', array('alt' => __('Views', true), 'title' => __("Show last views first", true)));
    $link = $this->Breadcrumb->crumbUrl($this->Breadcrumb->replace($crumbs, 'sort', 'viewed'));
    $out .= $this->Html->link($icon, $link, array('escape' => false));
    
    $icon = $this->Html->image('icons/folder_go.png', array('alt' => __('Name', true), 'title' => __("Order by name", true)));
    $link = $this->Search->getUri(false, array('sort' => 'name'), 'page');
    $out .= $this->Html->link($icon, $link, array('escape' => false));

    $out .= "</div>";

    $subMenu = array(
      'text' => $out,
      'type' => 'multi',
      'onmouseover' => "toggleVisibility('$id', 'inline');",
      'onmouseout' => "toggleVisibility('$id', 'inline');"
      );
    return $subMenu;
  }

  function _getPageItem() {
    $crumbs = $this->Breadcrumb->filterCrumbs($this->params['crumbs']);

    $link = $this->Breadcrumb->crumbUrl($this->Breadcrumb->replace($crumbs, 'show', 12));
    $out = $this->Html->link(__("Pagesize", true), $link);

    $pos = $this->Search->getPage(1) * $this->Search->getShow(1);
    $sizes = array(6, 12, 24, 60, 120, 240);
    $links = array();
    foreach ($sizes as $size) {
      $page = ceil($pos / $size);
      $link = $this->Breadcrumb->crumbUrl($this->Breadcrumb->replace($this->Breadcrumb->replace($crumbs, 'show', $size), 'page', $page));
      $links[] = $this->Html->link($size, $link, array('escape' => false));
    }

    $id = 'page-item';
    $out .= " <div class=\"actionlist\" id=\"$id\">";
    $out .= implode(", ", $links);
    $out .= "</div>";

    $subMenu = array(
      'text' => $out,
      'type' => 'multi',
      'onmouseover' => "toggleVisibility('$id', 'inline');",
      'onmouseout' => "toggleVisibility('$id', 'inline');"
      );
    return $subMenu;
  }

  function getMainMenu() {
    $out = '';
    $data = $this->data;
    $this->Search->initialize();
    $items = array();
    $this->_id = 0;

    $search = '/explorer/search';
    $items[] = array('text' => $this->Html->link(__('Advanced Search', true), $search));

    $items[] = $this->_getSlideshowItem();
    $out .= $this->Piclens->slideshow();

    $subMenu = $this->_getAssociationSubMenu('tag');
    if ($subMenu !== false) {
      $items[] = array('text' => __('Tags', true), 'type' => 'text', 'submenu' => array('items' => $subMenu));
    }

    $subMenu = $this->_getAssociationSubMenu('category');
    if ($subMenu !== false) {
      $items[] = array('text' => __('Categories', true), 'type' => 'text', 'submenu' => array('items' => $subMenu));
    }

    $subMenu = $this->_getAssociationSubMenu('location');
    if ($subMenu !== false) {
      $items[] = array('text' => __('Locations', true), 'type' => 'text', 'submenu' => array('items' => $subMenu));
    }

    $subItems = array();
    $subItems[] = $this->_getOrderItem();
    $subItems[] = $this->_getPageItem();
    $items[] = array('text' => __('Options', true), 'type' => 'text', 'submenu' => array('items' => $subItems));

    $menu = array('items' => $items);
    return $out.$this->Menu->getMainMenu($menu);
  }
}
?>
