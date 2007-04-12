<?php

include_once("$phtagr_lib/FileJpg.php");
include_once("$phtagr_lib/PreviewVideo.php");
include_once("$phtagr_lib/Iptc.php");

/** @class FileAvi
*/
class FileAvi extends FileJpg
{

function FileAvi($filename)
{
  $this->FileJpg($filename);
  $this->create_thumb();
  $this->set_image_filename($this->get_thumb_filename());
}

/** Searches the video thumbnail in the directory of the video file. This
 * function is called if the thumnail extension is neither in lowercase nor in
 * uppercase.
  @return Filename of the video thumbnail if it was found. If no thumbnail was
fund, an null is returnd */
function _search_thumb()
{
  global $log, $user;
  $filename=$this->get_filename();
  $dir=dirname($filename);
  if (!is_dir($dir))
    return null;

  $base=substr($filename, 0, strrpos($filename, ".")+1);
  $thm=strtolower($base."thm");
  $thumb=null;

  $log->trace("Search '$thm' in '$dir'", -1, $user->get_id());
  $dh=opendir($dir);
  while (false != ($file=readdir($dh)))
  {
    $file=$dir.DIRECTORY_SEPARATOR.$file;
    if (is_dir($file))
      continue;
    if (strtolower($file)==$thm)
    {
      $log->trace("Found '$file' in '$dir'", -1, $user->get_id());
      $thumb=$file;
      break;
    }
  }
  closedir($dh);

  return $thumb;
}

/** Returns the filename of a video thumbnail. If the movie file is
 * mvi_1234.avi it searches for mvi_1234.thm, mvi_1234.THM or it searches the
 * thumbnail file within the diretory. If no existing file is found, it returns
 * a default name (mvi_1234.thm) */
function get_thumb_filename()
{
  $filename=$this->get_filename();
  $base=substr($filename, 0, strrpos($filename, ".")+1);
  if (file_exists($base."THM"))
    return $base."THM";
  if (file_exists($base."thm"))
    return $base."thm";

  // Neither uppercase nor lower case files exists, search for it
  $thumb=$this->_search_thumb();
  if ($thumb!==null)
    return $thumb;

  // No video theme file found, return a default filename
  return $base."thm";
}

/** Creates an thumbnail of the movie and return true on success */
function create_thumb()
{
  global $conf, $log, $user;
  $filename=$this->get_filename();
  $thumb=$this->get_thumb_filename();

  @clearstatcache();
  if (file_exists($thumb))
    return true;
  if (!is_writeable(dirname($thumb)))
    return false;

  $cmd=$conf->get('bin.ffmpeg', 'ffmpeg')." -i \"$filename\" -t 0.001 -f mjpeg -y \"$thumb\"";
  $lines=array();
  $result=-1;
  exec($cmd, &$lines, $result);
  if ($result==127)
    $log->fatal("Command not found: $cmd", -1, $user->get_id());
  else
    $log->debug("Execute [returned $result]: ".$cmd, -1, $user->get_id());
  if ($result==0)
    return true;
  return false;
}

function is_writeable()
{
  $filename=$this->get_thumb_filename();
  if (is_writeable($filename) &&
    is_writeable(dirname($filename)))
    return true;
  return false;
}

function import($image)
{
  global $db, $log, $conf, $user;

  parent::import($image);

  $filename=$this->get_filename();

  $cmd=$conf->get('bin.ffmpeg', 'ffmpeg')." -i \"$filename\" -t 0.0 2>&1";
  $lines=array();
  $result=-1;
  exec($cmd, &$lines, $result);
  if ($result==127)
    $log->fatal("Command not found: $cmd", $image->get_id(), $user->get_id());
  else
    $log->debug("Execute [returned $result]: ".$cmd, $image->get_id(), $user->get_id());
  foreach ($lines as $line)
  {
    $words=preg_split("/[\s,]+/", trim($line));
    if ($words[0]=="Duration:")
    {
      $times=preg_split("/:/", $words[1]);
      $time=$times[0]*3600+$times[1]*60+intval($times[2]);
      $image->set_duration($time);
      $log->debug("Found duration: $time", $image->get_id(), $user->get_id());
    }
    elseif ($words[2]=="Video:")
    {
      list($width, $height)=split("x", $words[5]);
      $image->set_width($width);
      $image->set_height($height);
      $log->debug("Found size: $width x $height", $image->get_id(), $user->get_id());
    }
  }
  
  $image->set_name(basename($filename));
}

/** Returns the newest file time of the video or its thumb file */
function get_filetime()
{
  $filename=$this->get_filename();
  if (!file_exists($filename))
    return -1;

  $thumb=$this->get_thumb_filename();

  $video_time=filemtime($filename);
  if (file_exists($thumb))
  {
    $thumb_time=filemtime($thumb);
    return ($video_time>$thumb_time)?$video_time:$thumb_time;
  }
  else
  {
    return $video_time;
  }
}

function get_preview_handler($image)
{
  $preview=new PreviewVideo($image);
  return $preview;
}

}
?>
