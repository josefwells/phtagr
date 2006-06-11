<?php

global $prefix;
global $db;

include_once("$prefix/SectionBase.php");
include_once("$prefix/Image.php");
include_once("$prefix/Sql.php");


class SectionImage extends SectionBase
{

function SectionImage()
{
  $this->name="image";
}

/** print image preview table */
function print_navigation($search)
{
  global $db;
  
  if ($search==null)
    return;
    
  
  $pos=$search->get_pos();
  $page_size=$search->get_page_size();

  $cur_pos=$pos;
  if ($pos>1)
    $cur_pos-=1;
  else 
    $cur_pos=0;

  $search->set_pos($cur_pos);
  $search->set_page_size($pos-$cur_pos+2);
  $search->set_imageid(null);
  $sql=$search->get_query(2);

  $result=$db->query($sql);
  // we need at least 2 lines.
  if (!$result || mysql_num_rows($result)<2)
    return;

  // restore old page style
  $search->set_page_size($page_size);

  echo "\n<div class=\"navigator\">\n";
  while ($row=mysql_fetch_row($result))
  {
    $search->set_pos($cur_pos);
    // skip current image
    if ($cur_pos==$pos)
    {
      $url="index.php?section=explorer";
      $url.=$search->to_URL();
      echo "<a href=\"$url\">up</a>&nbsp;";
      $cur_pos++;
      continue;
    }

    $id=$row[0];
    $search->set_pos($cur_pos);
    
    $url="index.php?section=image&id=$id";
    $url.=$search->to_URL();
    
    if ($cur_pos<$pos)
      echo "<a href=\"$url\">prev</a>&nbsp;";
    else
      echo "<a href=\"$url\">next</a>";
    
    $cur_pos++;
  }
  echo "</div>\n";
}

function print_content()
{
  global $db;
  global $user;

  $search=new Search();
  $search->from_URL();
 
  echo "<h2>Image</h2>\n";
  
  if (!isset($_REQUEST['id']))
    return;
 
  $id=$_REQUEST['id'];
  $image=new Image($id);
  
  $name=$image->get_name();
  
  echo "<h3>$name</h3>\n";

  $this->print_navigation($search);
  
  $size=$image->get_size(600);
  echo "<p><img src=\"./image.php?id=$id&amp;type=preview\" alt=\"$name\" ".$size[2]."/></p>\n";
  if ($user->can_edit(&$image))
  {
    echo "<form action=\"index.php\" method=\"post\">\n";
    echo "<input type=\"hidden\" name=\"section\" value=\"image\" />\n";
    echo "<input type=\"hidden\" name=\"action\" value=\"edit\" />\n";
    echo $search->to_form();
  } 
  $image->print_caption(false);
  echo "<table class=\"imginfo\">\n";
  
  $ranking=0+strtr($image->get_ranking(), 'E', 'e');
  echo "  <tr><th>Clicks:</th><td>".$image->get_clicks()
    ." (Ranking: $ranking)</td></tr>\n";

  $sec=$image->get_date(true);
  $image->print_row_date($sec);
  $image->print_row_tags();
  echo "</table>\n";

  if ($user->can_edit(&$image))
    echo "</form>\n";

  if (!isset($_SESSION['img_viewed'][$id]))
    $image->update_ranking();

  $_SESSION['img_viewed'][$id]++;

  $this->print_navigation($search);
 
}

}

?>
