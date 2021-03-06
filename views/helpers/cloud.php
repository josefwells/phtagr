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
class CloudHelper extends AppHelper
{
  var $helpers = array('Html');

  /** Prints a tag cloud 
    @param data Cloud data
    @param urlPrefix Prefix of URL
    @return Cloud html */
  function cloud($data, $urlPrefix = false) {
    if (count($data) == 0) {
      return;
    }
    $max = max($data);
    $min = min($data);
    $sizes = array('xxs', 'xs', 's', 'm', 'l', 'xl', 'xxl');
    $width = min($max - $min, 7);

    $out = '';
    ksort($data);
    foreach($data as $name => $hits) {
      if ($max - $min > 0) {
        $percentage = ($hits - $min) / ($max - $min);
      } else {
        $percentage = 1;
      }
      $index = max(0, ceil($percentage * $width + (7 - $width) / 2) - 1);
      $out .= $this->Html->link($name, $urlPrefix.$name, array('class' => $sizes[$index]));
    }

    return $out;
  }
}
?>
