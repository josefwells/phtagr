<?php

include_once("$phtagr_lib/PreviewImage.php");

/** Create previews of an video and creates an flash movie via ffmpeg and
 * flvtool2
  @class PreviewVideo
*/
class PreviewVideo extends PreviewImage
{

function PreviewVideo($image)
{
  $this->PreviewImage($image);
}

/** Creates an thumbnail image from the video if required. The thumbnail will
 * be taken from the frame at 0.1*video_length.
  @param src Filename of the soure image */
function init($src)
{
  global $log, $user, $conf;
  $image=$this->get_image();
  
  // Generate the screen image first
  if ($image->is_video())
  {
    $video=$image->get_file_handler();
    if ($video)
    {
      $thumb=$video->get_thumb_filename();
      if (!file_exists($thumb)) 
        $video->create_thumb();
      $src=$thumb;
    } 
  }

  parent::init($src);
}

function get_filename_preview_movie()
{
  $image=$this->get_image();
  $file=sprintf("video%07d.preview.flv",$image->get_id());
  return $this->get_cache_path().$file;
}

/** Convert the movie to a flash movie if needed */
function create_preview_movie($inherit=false)
{
  global $log, $user, $conf;
  $image=$this->get_image();
  $flv=$this->get_filename_preview_movie();

  if (file_exists($flv) && 
    filemtime($flv) >= $image->get_modified(true))
    return;

  list($width, $height, $s)=$image->get_size(320);
  $cmd=$conf->get('bin.ffmpeg', 'ffmpeg')." -i \"".$image->get_filename()."\" "
    ."-s ${width}x$height -r 15 -b 250 -ar 22050 -ab 48 -y "
    ."\"$flv\"";
  $lines=array();
  $result=-1;
  exec($cmd, &$lines, &$result);
  if ($result==127)
    $log->fatal("Command not found: $cmd", $image->get_id(), $user->get_id());
  else
    $log->info("Execute [returned: $result]: $cmd", $image->get_id(), $user->get_id());
  @chmod($flv, 0644);

  $cmd=$conf->get('bin.flvtool2', 'flvtool2')." -U \"$flv\"";
  $lines=array();
  $result=-1;
  exec($cmd, &$lines, &$result);
  if ($result==127)
    $log->fatal("Command not found: $cmd", $image->get_id(), $user->get_id());
  else
    $log->info("Execute [returned $result]: $cmd", $image->get_id(), $user->get_id());
}

function get_filenames()
{
  $files=parent::get_filenames();
  array_push($files, $this->get_filename_preview_movie());
  return $files;
}

}

?>
