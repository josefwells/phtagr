<?php
/*
 * phtagr.
 * 
 * Multi-user image gallery.
 * 
 * Copyright (C) 2006-2008 Sebastian Felis, sebastian@phtagr.org
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

require_once "HTTP/WebDAV/Server.php";

/**
 * WebdavServer 
 *
 * based on Filesystem.php from Hartmut Holzgraefe <hartmut@php.net>
 *
 */
class WebdavServer extends HTTP_WebDAV_Server
{

  /**
   * Root directory for WebDAV access
   *
   * Defaults to webserver document root (set by ServeRequest)
   *
   * @access private
   * @var  string
   */
  var $_fsRoot="";

  var $_davRoot="";

  var $_putFsPath="";

  var $controller = null;

  function WebdavServer() {
    $this->HTTP_WebDAV_Server();
    $this->_fsRoot=$_SERVER['DOCUMENT_ROOT'];
    $this->_davRoot="";
  }

  function setController(&$controller) {
    $this->controller = &$controller;
    // set current controller URL
    $this->setDavRoot($controller->webroot.$controller->name);
  }

  /** Set a new filesystem root directory
    @param root Base directory
    @return True on success, false otherwise
    @note The root directory must be exists, otherwise it returns false */
  function setFsRoot($root) {
    if (!is_dir($root))
      return false;
    $this->_fsRoot=$this->_unslashify($root);
    return true;
  }

  function setDavRoot($root) {
    $this->_davRoot=$this->_unslashify($root);
    $this->controller->Logger->trace("setDavRoot to $root");
    return true;
  }

  /** Returns the canonicalized path 
    @param path
    @return canonicalized path */
  function canonicalpath($path) {
    $paths=explode('/', $path);
    $result=array();
    for ($i=0; $i<sizeof($paths); $i++) {
      if ($paths[$i]==='' || $paths[$i]=='.') 
        continue;
      if ($paths[$i]=='..') { 
        array_pop($result);
        continue;
      }
      array_push($result, $paths[$i]);
    }
    return '/'.implode('/', $result);
  }

  /** Returns the relative WebDAV path to the current WebDAV path 
    @param path webdav path
    @return relative WebDAV path */
  function getRelativeDavpath($path) {
    $lenroot=strlen($this->_davRoot);
    $lenpath=strlen($path);

    $relativePath="/";
    if ($lenpath > $lenroot && $path[$lenroot]=='/') {
      $pos=strpos($path, $this->_davRoot);
      if ($pos===0) {
        $relativePath=$this->canonicalpath(substr($path, $lenroot));
      }
    }
    $this->controller->Logger->trace("get_relativ_davpath($path)==$relativePath");
    return $relativePath;
  }

  /** Returns the filesystem path to the corresponding request path
    @param path Request path
    @return filesystem path */
  function getFsPath($path) {
    //$relativePath=$this->getRelativeDavpath($path);
    $relativePath=$this->canonicalpath($path);
    if ($relativePath!='/') {
      $fspath=$this->_mergePathes($this->_fsRoot, $relativePath);
    } else {
      $fspath=$this->_fsRoot;
    }
    $this->controller->Logger->trace("getFsPath($path)=$fspath");
    return $fspath;
  }

  /** Sets a new realm
    @param realm Realm name */
  function setRealm($realm) {
    $this->_realm=$realm;
  }

  /** Set string for server identification 
    @param text Text of server identification */
  function setPoweredBy($text) {
    $this->dav_powered_by=$text;
  }

  /** Encodes the paths of an url. E.g '/space test/' becomes '/space%20test/' 
    @param path Path
    @return Escaped path
    @note Requires PHP 5 (uses references in foreach statement) */
  function pathRawurlencode($path) {
    $paths=explode('/', $path);
    foreach ($paths as &$part)
      $part=rawurlencode($part);
    return implode('/', $paths);
  }

  function ServeRequest($base=false) {
    $this->controller->Logger->debug("ServeRequest: $base");

    // special treatment for litmus compliance test
    // reply on its identifier header
    // not needed for the test itself but eases debugging
    if (function_exists("apache_request_headers")) {
      foreach (apache_request_headers() as $key => $value) {
        if (stristr($key, "litmus")) {
          $this->controller->Logger->trace("Litmus test $value");
          header("X-Litmus-reply: ".$value);
        }
      }
    }

    if (strpos($base, $this->_davRoot)!==0) 
      $this->_SERVER['PATH_INFO']='/'; 
    else 
      $this->_SERVER['PATH_INFO']=substr($base, strlen($this->_davRoot)); 
    $this->_SERVER['SCRIPT_NAME']=$this->_davRoot;

    // let the base class do all the work
    parent::ServeRequest();
  }

  /**
   * No authentication is needed here
   *
   * @access private
   * @param  string  HTTP Authentication type (Basic, Digest, ...)
   * @param  string  Username
   * @param  string  Password
   * @return bool  true on successful authentication
   */
  function checkAuth($type, $auser, $apass) {
    return true;
  }

  /** Checks if the path could be read by the user
    @param path System directory or filename
    @return True if user is authorized to read directory. False otherwise 
    @todo This function must be implemented */
  function _hasReadRights($fspath) {
    $allow = $this->controller->Image->canRead($fspath, $this->controller->getUser());
    $this->controller->Logger->trace(($allow?"Permit":"Deny")." access to '$fspath'");
    return $allow;
  }

  /**
   * PROPFIND method handler
   *
   * @param  array  general parameter passing array
   * @param  array  return array for file properties
   * @return bool   true on success
   */
  function PROPFIND(&$options, &$files) {
    // get absolute fs path to requested resource
    $fspath=$this->getFsPath($options["path"]);
    $this->controller->Logger->debug("PROFIND: '$fspath' ({$options["path"]})");
      
    // sanity check
    if (!file_exists($fspath)) {
      $this->controller->Logger->err("File '$fspath' does not exists");
      return false;
    }

    $userRole = $this->controller->getUserRole();
    if ($userRole <= ROLE_GUEST &&
      !$this->_hasReadRights($fspath)) {
      $this->controller->Logger->warn("User is not allowed to read '$fspath'");
      return false;
    }

    // prepare property array
    $files["files"]=array();

    // store information for the requested path itself
    $files["files"][]=$this->fileinfo($options["path"]);

    // information for contained resources requested?
    if (!empty($options["depth"])) { // TODO check for is_dir() first?
        
      // make sure path ends with '/'
      $options["path"]=$this->_slashify($options["path"]);

      // try to open directory
      $handle=@opendir($fspath);
      $this->controller->Logger->debug("Read directory '$fspath'"); 
      if ($handle) {
        $fspath=Folder::slashTerm($fspath);
        // ok, now get all its contents
        while ($filename=readdir($handle)) {
          if ($filename == "." || $filename == "..") 
            continue;
          // @todo Improve the read check if user is a guest. Query files from
          // the database instead
          if ($userRole <= ROLE_GUEST && !$this->_hasReadRights($fspath.$filename))
            continue; 
          $files["files"][]=$this->fileinfo($options["path"].$filename);
        }
        // TODO recursion needed if "Depth: infinite"
      }
    }

    // ok, all done
    return true;
  } 
    
  /**
   * Get properties for a single file/resource
   *
   * @param  string  resource path
   * @return array   resource properties
   */
  function fileinfo($path) {
    // map URI path to filesystem path
    $fspath=$this->getFsPath($path);
    $this->controller->Logger->debug("fileinfo: '$fspath' ($path)");

    // create result array
    $info=array();
    // TODO remove slash append code when base clase is able to do it itself
    $info["path"] =is_dir($fspath) ? $this->_slashify($path) : $path; 
    $info["path"] =$this->pathRawurlencode($info["path"]);
    $info["props"]=array();
      
    // no special beautified displayname here ...
    $info["props"][]=$this->mkprop("displayname", strtoupper($path));
      
    // creation and modification time
    $info["props"][]=$this->mkprop("creationdate",  filectime($fspath));
    $info["props"][]=$this->mkprop("getlastmodified", filemtime($fspath));

    // type and size (caller already made sure that path exists)
    if (is_dir($fspath)) {
      // directory (WebDAV collection)
      $info["props"][]=$this->mkprop("resourcetype", "collection");
      $info["props"][]=$this->mkprop("getcontenttype", "httpd/unix-directory");       
    } else {
      // plain file (WebDAV resource)
      $info["props"][]=$this->mkprop("resourcetype", "");
      if (is_readable($fspath)) {
        $info["props"][]=$this->mkprop("getcontenttype", $this->_mimetype($fspath));
      } else {
        $info["props"][]=$this->mkprop("getcontenttype", "application/x-non-readable");
      }         
      $info["props"][]=$this->mkprop("getcontentlength", filesize($fspath));
    }

    // get additional properties from database
    $image=$this->controller->Image->findByFilename($fspath);
    if ($image) {
      foreach($image['Property'] as $property) {
        $info['props'][]=$this->mkprop(
          $property['ns'], 
          $property['name'], 
          $property['value']);
      }
    }
    foreach ($info['props'] as $prop) {
      $this->controller->Logger->trace('{'.$prop['ns']."}{$prop['name']}:{$prop['val']}");
    }

    //$this->controller->Logger->trace("fileinfo: info=".print_r($info, true));
    return $info;
  }

  /**
   * detect if a given program is found in the search PATH
   *
   * helper function used by _mimetype() to detect if the 
   * external 'file' utility is available
   *
   * @param  string  program name
   * @param  string  optional search path, defaults to $PATH
   * @return bool  true if executable program found in path
   */
  function _canExecute($name, $path=false) {
    // path defaults to PATH from environment if not set
    if ($path === false) {
      $path=getenv("PATH");
    }
      
    // check method depends on operating system
    if (!strncmp(PHP_OS, "WIN", 3)) {
      // on Windows an appropriate COM or EXE file needs to exist
      $exts=array(".exe", ".com");
      $check_fn="file_exists";
    } else {
      // anywhere else we look for an executable file of that name
      $exts=array("");
      $check_fn="is_executable";
    }
      
    // now check the directories in the path for the program
    foreach (explode(PATH_SEPARATOR, $path) as $dir) {
      // skip invalid path entries
      if (!file_exists($dir)) continue;
      if (!is_dir($dir)) continue;

      // and now look for the file
      foreach ($exts as $ext) {
        if ($check_fn("$dir/$name".$ext)) return true;
      }
    }

    return false;
  }

  /**
   * try to detect the mime type of a file
   *
   * @param  string  file path
   * @return string  guessed mime type
   */
  function _mimetype($fspath) {
    if (@is_dir($fspath)) {
      // directories are easy
      return "httpd/unix-directory"; 
    } else if (function_exists("mime_content_type")) {
      // use mime magic extension if available
      $mimeType=mime_content_type($fspath);
    } else if ($this->_canExecute("file")) {
      // it looks like we have a 'file' command, 
      // lets see it it does have mime support
      $fp =popen("file -i '$fspath' 2>/dev/null", "r");
      $reply=fgets($fp);
      pclose($fp);
        
      // popen will not return an error if the binary was not found
      // and find may not have mime support using "-i"
      // so we test the format of the returned string 
        
      // the reply begins with the requested filename
      if (!strncmp($reply, "$fspath: ", strlen($fspath)+2)) {           
        $reply=substr($reply, strlen($fspath)+2);
        // followed by the mime type (maybe including options)
        if (preg_match('|^[[:alnum:]_-]+/[[:alnum:]_-]+;?.*|', $reply, $matches)) {
          $mimeType=$matches[0];
        }
      }
    } 
      
    if (empty($mimeType)) {
      // Fallback solution: try to guess the type by the file extension
      // TODO: add more ...
      // TODO: it has been suggested to delegate mimetype detection 
      //     to apache but this has at least three issues:
      //     - works only with apache
      //     - needs file to be within the document tree
      //     - requires apache mod_magic 
      // TODO: can we use the registry for this on Windows?
      //     OTOH if the server is Windos the clients are likely to 
      //     be Windows, too, and tend do ignore the Content-Type
      //     anyway (overriding it with information taken from
      //     the registry)
      // TODO: have a seperate PEAR class for mimetype detection?
      switch (strtolower(strrchr(basename($fspath), "."))) {
      case ".html":
        $mimeType="text/html";
        break;
      case ".gif":
        $mimeType="image/gif";
        break;
      case ".jpg":
        $mimeType="image/jpeg";
        break;
      default: 
        $mimeType="application/octet-stream";
        break;
      }
    }
      
    return $mimeType;
  }

  /**
   * GET method handler
   * 
   * @param  array  parameter passing array
   * @return bool   true on success
   */
  function GET(&$options) {
    // get absolute fs path to requested resource
    $fspath=$this->getFsPath($options["path"]);
    $this->controller->Logger->debug("GET: '$fspath' ({$options["path"]})");

    // sanity check
    if (!file_exists($fspath)) { 
      $this->controller->Logger->warn("Content '$fspath' does not exists");
      return false;
    }
      
    if ($this->controller->getUserRole() <= ROLE_GUEST &&
      !$this->_hasReadRights($fspath)) {
      $this->controller->Logger->warn("User is not allowed to view content of '$fspath'");
      return false;
    }

    // is this a collection?
    if (is_dir($fspath)) {
      return $this->GetDir($fspath, $options);
    }
      
    // TODO synchronize file if neccessary

    // detect resource type
    $options['mimetype']=$this->_mimetype($fspath); 
        
    // detect modification time
    // see rfc2518, section 13.7
    // some clients seem to treat this as a reverse rule
    // requiering a Last-Modified header if the getlastmodified header was set
    $options['mtime']=filemtime($fspath);
      
    // detect resource size
    $options['size']=filesize($fspath);
      
    // no need to check result here, it is handled by the base class
    $options['stream']=fopen($fspath, "r");
    
    return true;
  }

  /**
   * GET method handler for directories
   *
   * This is a very simple mod_index lookalike.
   * See RFC 2518, Section 8.4 on GET/HEAD for collections
   *
   * @param  string  directory path
   * @return void  function has to handle HTTP response itself
   */
  function GetDir($fspath, &$options) {
    $this->controller->Logger->debug("GetDir: '$fspath' ({$options["path"]})");

    $path=$this->_slashify($options["path"]);

    $baseUri=@$this->_SERVER["HTTPS"]==="on"?"https:":"http:";
    $baseUri.="//".$_SERVER['HTTP_HOST'].$this->_davRoot;
    
    //$this->controller->Logger->trace("baseUri=".$baseUri);

    if ($path != $options["path"]) {
      header("Location: ".$baseUri.$path);
      exit;
    }
    // fixed width directory column format
    $format="%15s  %-19s  %-s\n";

    $handle=@opendir($fspath);
    if (!$handle) {
      return false;
    }

    echo "<html><head><title>Index of ".htmlspecialchars($options['path'])."</title></head>\n";
      
    echo "<h1>Index of ".htmlspecialchars($options['path'])."</h1>\n";
      
    echo "<pre>";
    printf($format, "Size", "Last modified", "Filename");
    echo "<hr>";

    // Current directory
    $link=$this->_unslashify($baseUri.$this->pathRawurlencode($path));
    printf($format, 
      number_format(filesize($fspath)),
      strftime("%Y-%m-%d %H:%M:%S", filemtime($fspath)), 
      "<a href='$link'>.</a>");

    // Upper directory
    $paths=explode('/', trim($options['path'], '/'));
    if (strlen($paths[0])) {
      $parentFsPath=dirname($fspath);

      unset($paths[count($paths)-1]);
      $parentPath='/'.implode('/', $paths);

      $link=$this->_unslashify($baseUri.$this->pathRawurlencode($parentPath));
      printf($format, 
        number_format(filesize($parentFsPath)),
        strftime("%Y-%m-%d %H:%M:%S", filemtime($parentFsPath)), 
        "<a href='$link'>..</a>");
    }

    $dirs=array();
    $files=array();
    while ($filename=readdir($handle)) {
      if ($filename == "." || $filename == "..") 
        continue;

      if (is_dir($fspath.'/'.$filename))
        array_push($dirs, $filename);
      else 
        array_push($files, $filename);
    }
    asort($dirs);
    asort($files);
    $files=array_merge($dirs,$files);
    foreach($files as $filename) {
      $fullpath=$fspath."/".$filename;
      // check access for guest accounts
      if (!$this->_hasReadRights($fullpath))
        continue;

      $name  =htmlspecialchars($this->_unslashify($filename));
      $link=$baseUri.$this->pathRawurlencode($path.$name);
      printf($format, 
        number_format(filesize($fullpath)),
        strftime("%Y-%m-%d %H:%M:%S", filemtime($fullpath)), 
        "<a href='$link'>$name</a>");
    }
    echo "</pre>";

    closedir($handle);

    echo "</html>\n";

    exit;
  }

  function http_PUT() {
    // http_PUT() calls PUT()
    parent::http_PUT();

    // Update filename on success upload
    $status=substr($this->_http_status, 0, 3);
    if ($status < 200 || $status>=300) {
      $this->controller->Logger->warn("HTTP status is $status. Return false");
      return false;
    }

    $fspath=$this->_putFsPath;
    if (!file_exists($fspath)) {
      $this->controller->Logger->err("Could not determine fspath=$fspath");
      return false;
    }
   
    $image = $this->controller->Image->findByFilename($fspath);
    if ($image) {
      $this->controller->Image->deactivate(&$image);
      $this->controller->Image->updateFile(&$image);
      if ($this->controller->Image->save($image)) {
        $this->controller->Logger->info("Update file '{$image['Image']['path']}{$image['Image']['file']}' to size {$image['Image']['bytes']} bytes");
      } else {
        $this->controller->Logger->err("Could not update filesize of '{$image['Image']['path']}{$image['Image']['file']}'");
      }
    } else {
      $id = $this->controller->Image->insertFile($fspath, $this->controller->getUser());
      if ($id) {
        $this->controller->Logger->info("Add file '$fspath' to database with id $id");
      } else {
        $this->controller->Logger->err("Could not add file '$fspath' to database");
      }
    }
  }

  /**
   * PUT method handler
   * 
   * @param  array  parameter passing array
   * @return bool   true on success
   */
  function PUT(&$options) {
    if ($this->controller->getUserRole() <= ROLE_GUEST) {
      $this->controller->Logger->warn("PUT denyied for guests");
      return "403 Forbidden";
    }

    $fspath=$this->getFsPath($options["path"]);

    if (!@is_dir(dirname($fspath))) {
      $this->controller->Logger->warn("dir of '$fspath' does not exists");
      return "409 Conflict";
    }

    if (isset($options['ranges'])) {
      // @todo Ranges are not supported yet
      $this->controller->Logger->err("Ranges are not supported yet");
      return "409 Conflict";
    }

    $size=$options['content_length'];
    if ($size<0) {
      $this->controller->Logger->warn("Size is negative");
      return "409 Conflict";
    }

    // Check users quota
    if (!$this->controller->User->canUpload($this->controller->getUser(), $size)) {
      $this->controller->Logger->warn("Quota exceed. Deny upload of $size Bytes");
      return "409 Conflict";
    }

    $this->controller->Logger->debug("PUT: '$fspath' ($size Bytes, {$options["path"]})");
    $options["new"]=!file_exists($fspath);

    // save path
    $this->_putFsPath=$fspath;
    $fp=fopen($fspath, "w");
    if ($fp===false)
      $this->controller->Logger->err("fopen('$fspath', 'w')===false");

    return $fp;
  }

  /**
   * MKCOL method handler
   *
   * @param  array  general parameter passing array
   * @return bool   true on success
   */
  function MKCOL($options) {       
    if ($this->controller->getUserRole() <= ROLE_GUEST) {
      $this->controll->Logger->warn("MKCOL: Denied for guests");
      return "403 Forbidden";
    }
    $fspath=$this->getFsPath($options["path"]);
    $this->controller->Logger->info("MKCOL: '$fspath' ({$options["path"]})");

    $parent=dirname($fspath);
    if (!file_exists($parent)) {
      return "409 Conflict";
    }

    if (!is_dir($parent)) {
      return "403 Forbidden";
    }

    if (file_exists($fspath)) {
      $this->controller->Logger->err("MKCOL: File '$fspath' already exists");
      return "405 Method not allowed";
    }

    if (!empty($this->_SERVER["CONTENT_LENGTH"])) { // no body parsing yet
      return "415 Unsupported media type";
    }
      
    if (!@mkdir($fspath, 0775)) {
      $this->controller->Logger->err("Could not create directory '$fspath'");
      return "403 Forbidden";         
    }

    return ("201 Created");
  }
    
  /**
   * DELETE method handler
   *
   * @param  array  general parameter passing array
   * @return bool   true on success
   */
  function DELETE($options) {
    if ($this->controller->getUserRole() <= ROLE_GUEST) {
      $this->controller->Logger->warn("DELETE denyied for guests");
      return "403 Forbidden";
    }

    $fspath=$this->getFsPath($options["path"]);
    if (!file_exists($fspath)) {
      $this->controller->Logger->err("DELETE failed. file '$fspath' does not exists");
      return "404 Not found";
    }
        
    $this->controller->Logger->debug("DELETE '$fspath' ({$options['path']})");
    if (!is_dir($fspath)) {
      $image = $this->controller->Image->findByFilename($fspath);
      if (!$image) {
        $this->controller->Logger->warn("File '$fspath' is not in database. Delete file anyway");
      } else {
        $this->controller->FileCache->delete($image['Image']['user_id'], $image['Image']['id']);
        if (!$this->controller->Image->delete($image['Image']['id'])) {
          $this->controller->Logger->err("Could not delete image with id {$image['Image']['id']} (filename '$fspath')");
        } else {
          $this->controller->Logger->info("Delete image with id {$image['Image']['id']} (filename '$fspath')");
        }
      }
      unlink($fspath);
      $this->controller->Logger->info("file '$fspath' deleted!");
    } else {
      uses('sanitize');
      $sanitize = new Sanitize();
      $sqlFspath = $sanitize->escape($fspath);
      $images = $this->controller->Image->findAll("Image.path LIKE '{$sqlFspath}%'", array('Image.id', 'Image.user_id', 'Image.path', 'Image.file'));
      if (!$images) {
        $this->controller->Logger->warn("No file with directory '$fspath' is in database");
      } else {
        $this->controller->Logger->trace("Found ".count($images)." file(s) in database for directory '$fspath'");
        foreach($images as $image) {
          $this->controller->FileCache->delete($image['Image']['user_id'], $image['Image']['id']);
          if (!$this->controller->Image->delete($image['Image']['id'])) {
            $this->controller->Logger->err("Could not delete image with id {$image['Image']['id']} (filename '{$image['Image']['path']}{$image['Image']['file']}')");
          } else {
            $this->controller->Logger->info("Delete image with id {$image['Image']['id']} (filename '{$image['Image']['path']}{$image['Image']['file']}')");
          }
        }
      }
      $folder =& new Folder();
      $folder->delete($fspath);
      $this->controller->Logger->info("Directory '$fspath' deleted!");
    }
    return "204 No Content";
  }

  /**
   * MOVE method handler
   *
   * @param  array  general parameter passing array
   * @return bool   true on success
   */
  function MOVE($options) {
    return $this->COPY($options, true);
  }

  /**
   * COPY method handler
   *
   * @param  array  general parameter passing array
   * @return bool   true on success
   */
  function COPY($options, $del=false) {

    if ($this->controller->getUserRole() <= ROLE_GUEST) {
      $this->controller->Logger->warn("COPY/MOVE denied for guests");
      return "403 Forbidden";
    }

    // TODO Property updates still broken (Litmus should detect this?)

    if (!empty($this->_SERVER["CONTENT_LENGTH"])) { // no body parsing yet
      $this->controller->Logger->err("Unsupported media type");
      return "415 Unsupported media type";
    }

    // no copying to different WebDAV Servers yet
    if (isset($options["dest_url"])) {
      $this->controller->Logger->err("Bad gateway");
      return "502 bad gateway";
    }

    $source=$this->getFsPath($options["path"]);
    $this->controller->Logger->debug("COPY '$source' ({$options['path']} -> {$options['dest']}, del=".($del?'true':'false').")");

    if (!file_exists($source)) {
      $this->controller->Logger->warn("Source '$source' not found");
      return "404 Not found";
    }

    $dest=$this->getFsPath($options["dest"]);
    $new=!file_exists($dest);
    $existingCol=false;

    if (!$new) {
      if ($del && is_dir($dest)) {
        if (!$options["overwrite"]) {
          $this->controller->Logger->warn("Precondition failed. Overwrite not set");
          return "412 precondition failed";
        }
        $dest.=basename($source);
        if (file_exists($dest)) {
          $options["dest"].=basename($source);
        } else {
          $new=true;
          $existingCol=true;
        }
      }
    }

    if (!$new) {
      if ($options["overwrite"]) {
        $stat=$this->DELETE(array("path" => $options["dest"]));
        if (($stat{0} != "2") && (substr($stat, 0, 3) != "404")) {
          $this->controller->Logger->err("Could not delete existing file '{$options["dest"]}");
          return $stat; 
        }
        $this->controller->Logger->info("Delete existing file '{$options["dest"]}' for overwriting");
        @clearstatcache();
      } else {
        $this->controller->Logger->warn("Precondition failed. Cant overwrite existing destination");
        return "412 precondition failed";
      }
    }

    if (is_dir($source) && ($options["depth"] == 1)) {
      // RFC 2518 Section 9.2, last paragraph
      $this->controller->Logger->err("Bad request: Source is a directory, but depth is ".$options["depth"]);
      return "400 Bad request";
    }

    if ($del) {
      // move file(s)
      if (!is_dir($source)) { 
        if (!rename($source, $dest)) {
          $this->controller->Logger->err("Could not rename file from '$source' to '$dest'");
          return "500 Internal server error";
        }
        if (!$this->controller->Image->move($source, $dest)) {
          $this->controller->Logger->warn("Could not update file in database from file '$source' to '$dest'");
          rename($dest, $source);
          return "500 Internal server error";
        } 
        $this->controller->Logger->info("Renamed file from '$source' to '$dest'");
      } elseif (is_dir($dest) || !file_exists($dest)) {
        if (!rename($source, $dest)) {
          $this->controller->Logger->err("Could not rename directory from '$source' to '$dest'");
          return "500 Internal server error";
        }
        if (!$this->controller->Image->moveAll($source, $dest)) {
          $this->controller->Logger->err("Could not update file in database from directory '$source' to directory '$dest'");
          rename($dest, $source);
          return "500 Internal server error";
        } 
        $this->controller->Logger->info("Renamed directory from '$source' to '$dest'");
      } else {
        $this->controller->Logger->err("Move from directory '$source' to file '$dest' not allowed!");
        return "412 precondition failed";
      }
    } else {
      if (is_dir($source)) {
        $folder =& new Folder($source);
        list($dirs, $files) = $folder->tree($source);
        sort($dirs);
        sort($files);
        // TODO check users quota for all files to copy
        // Create required directories
        foreach ($dirs as $dir) {
          $destdir=str_replace($source, $dest, $dir);
          $this->controller->Logger->info($destdir);
          if (!file_exists($destdir) && !@mkdir($destdir)) {
            $this->controller->Logger->err("Could not create directory '$destdir'");
            return "500 Internal server error";
          }
          // COPY properties
        }
      } else {
        $dirs = array();
        // TODO check users quota
        $files = array($source);
      }

      $user = $this->controller->getUser();
      foreach ($files as $file) {
        $destfile=str_replace($source, $dest, $file);
          
        if (!@copy($file, $destfile)) {
          $this->controller->Logger->err("Could not copy file '$file' to '$destfile'");
          return "409 Conflict";
        }
        $dstImageId = $this->controller->Image->insertFile($destfile, $user);
        if (!$dstImageId) {
          $this->controller->Logger->err("Could not insert copied file '$destfile' to database (from '$file')");
          unlink($destfile);
          return "409 Conflict";
        } else {
          // Copy all properties
          $srcImage = $this->controller->Image->findByFilenam($file);
          if (!$srcImage) {
            $this->controller->Logger->warn("Could not found source '$file' in database");
          } else {
            if (!empty($srcImage['Property'])) {
              $this->controller->Property->copy($srcImage, $dstImageId);
              $this->controller->Logger->debug("Copy properties from '$file' to '$destfile'");
            }
          }
        }
        $this->controller->Logger->info("Insert copied file '$destfile' to database (from '$file')");
      }
    }

    return ($new && !$existingCol) ? "201 Created" : "204 No Content";     
  }

  /**
   * PROPPATCH method handler
   *
   * @param  array  general parameter passing array
   * @return bool   true on success
   */
  function PROPPATCH(&$options) {
    $this->controller->Logger->debug("PROPATCH: ".$options["path"]);

    $path=$options["path"];
    $fspath=$this->getFsPath($path);
    $imageId=$this->controller->Image->filenameExists($fspath);
    if (!$imageId) {
      $this->controller->Logger->err("Filename '$fspath' does not exists");
      return "";
    }

    foreach ($options["props"] as $key => $prop) {
      if ($prop["ns"] == "DAV:") {
        $options["props"][$key]['status']="403 Forbidden";
      } else {
        $property = $this->controller->Property->find(array('Property.image_id' => $imageId, 'Property.name' => $prop['name'], 'Property.ns' => $prop['ns']));
        if (isset($prop["val"])) {
          if (!$property) {
            $property = array('Property' => array(
              'image_id' => $imageId, 
              'name' => $prop['name'], 
              'ns' => $prop['ns'], 
              'value' => $prop['val']));
            $this->controller->Logger->debug("Create new property for image $imageId: {$prop['ns']}:{$prop['name']}='{$prop['val']}'");
            $property = $this->controller->Property->create($property);
          } else {
            $property['Property']['value'] = $prop['val'];
            $this->controller->Logger->debug("Set new property value for image $imageId: {$prop['ns']}:{$prop['name']}='{$prop['val']}'");
            $this->controller->Property->id = $property['Property']['id'];
          }
          if (!$this->controller->Property->save($property)) {
            $this->controller->Logger->err("Could not save property");
          }
        } elseif ($property) {
          $this->controller->Logger->debug("Delete property of image $imageId: {$prop['ns']}:{$prop['name']}");
          $this->controller->Property->delete($property['Property']['id']); 
        }     
      }
    }
            
    return "";
  }

  /**
   * LOCK method handler
   *
   * @param  array  general parameter passing array
   * @return bool   true on success
   */
  function LOCK(&$options) {
    $this->controller->Logger->info("LOCK: ".$options["path"]);

    // get absolute fs path to requested resource
    $fspath=$this->getFsPath($options["path"]);

    // TODO recursive locks on directories not supported yet
    if (is_dir($fspath) && !empty($options["depth"])) {
      $this->controller->Logger->warn("recursive locks on directories not supported yet!");
      return "409 Conflict";
    }

    $options["timeout"]=time()+300; // 5min. hardcoded

    // Delete expired locks
    $this->controller->Lock->deleteAll(array('Lock.expires' => "< '".date('Y-m-d H:i:s', time())."'"));

    $imageId = $this->controller->Image->filenameExists($fspath);
    if (!$imageId) {
      $this->controller->Logger->warn("Could not find file with path '$fspath'");
      return true;
    }

    if (isset($options["update"])) { // Lock Update
      $lock = $this->controller->Lock->find(array('Lock.image_id' => $imageId, 'Lock.token' => $options['update']));
      
      if ($lock) {
        $lock['Lock']['expires'] = date('Y-m-d H:i:s', $options['timeout']);
        $this->controller->Lock->save($lock);
        $this->controller->Logger->info("Updated lock for '$fspath': ".$lock['Lock']['expires']);

        $options['owner']=$lock['Lock']['owner'];
        $options['scope']=$lock['Lock']["exclusivelock"] ? "exclusive" : "shared";
        $options['type']=$lock['Lock']["exclusivelock"] ? "write"   : "read";

        return true;
      } else {
        $this->controller->Logger->warn("No lock found for lock update: '$fspath'");
        return false;
      }
    }
      
    $lock = array('Lock' => array(
      'token' => $options['locktoken'],
      'image_id' => $imageId,
      'owner' => $options['owner'],
      'expires' => date('Y-m-d H:i:s', $options['timeout']),
      'exclusivelock' => ($options['scope']==="exclusive"?"1":"0")
      ));
    $lock = $this->controller->Lock->create($lock);
    if ($this->controller->Lock->save($lock)) {
      $this->controller->Logger->info("Created lock for '$fspath': ".$lock['Lock']['expires']);
      return "200 OK";
    } else {
      $this->controller->Logger->err("Could not save lock of image $imageId");
      $this->controller->Logger->trace($lock);
      return "409 Conflict";
    }
  }

  /**
   * UNLOCK method handler
   *
   * @param  array  general parameter passing array
   * @return bool   true on success
   */
  function UNLOCK(&$options) {
    $this->controller->Logger->info("UNLOCK: ".$options["path"]);

    $fspath = $this->getFsPath($options["path"]);
    $imageId = $this->controller->Image->filenameExists($fspath);
    if (!$imageId) {
      $this->controller->Logger->err("Could not find file for path '$fspath'");
      return "409 Conflict";
    }
    $lock = $this->controller->Lock->find(array('Lock.image_id' => $imageId, 'Lock.token' => $options['token']));
    if (!$lock) {
      $this->controller->Logger->err("Could not find lock for image $imageId");
      return "409 Conflict";
    }
    $this->controller->Lock->del($lock['Lock']['id']);
    $this->controller->Logger->info("Deleted lock for '$fspath'");
    return "204 No Content";
  }

  /**
   * checkLock() helper
   *
   * @param  string resource path to check for locks
   * @return bool   true on success
   */
  function checkLock($path) {
    $this->controller->Logger->info("checkLock: ".$path);
    $result=false;
      
    $res=false;
   
    $fspath = $this->getFsPath($path);
    $imageId = $this->controller->Image->filenameExists($fspath);
    if (!$imageId) {
      $this->controller->Logger->warn("Could not find file with path '$fspath'");
      return false;
    }
    $lock = $this->controller->Lock->findByImageId($imageId);
    
    if (!$lock) {
      $this->controller->Logger->debug("No lock found for '$fspath'");
    } else {
      $result=array(
        'type'  => 'write',
        'scope' => $lock['Lock']['exclusivelock'] ? 'exclusive' : 'shared',
        'depth' => 0,
        'owner' => $lock['Lock']['owner'],
        'token' => $lock['Lock']['token'],
        'created' => strtotime($lock['Lock']['created']),
        'modified' => strtotime($lock['Lock']['modified']),
        'expires' => strtotime($lock['Lock']['expires'])
      );
    }

    return $result;
  }

}


/*
 * Local variables:
 * tab-width: 4
 * c-basic-offset: 4
 * indent-tabs-mode:nil
 * End:
 */
?>
