<?php
/**
 * User: johnx
 * Date: 11/9/12
 * Time: 12:49 PM
 */
#exceptional
if(!defined('SAE_ENABLED')){
    header('Location: /');
    die();
}

$SAE_FS = new SaeStorage();
define('SAES3_DEFAULT_STORAGE', 'magevar');

function _sae_write_file($filename, $data, $domain=SAES3_DEFAULT_STORAGE, $size=-1, $gzip=true){
    global $SAE_FS;
    $compress = !!$gzip;
    $attr = $compress ? $attr=array('encoding'=>'gzip') : array();
    return $SAE_FS->write($domain, $filename, $data, $size, $attr, $compress);
}

function _sae_read_file($filename, $domain=SAES3_DEFAULT_STORAGE){
    global $SAE_FS;
    return $SAE_FS->read($domain, $filename);
}

function _sae_remove_file($filename, $domain=SAES3_DEFAULT_STORAGE){
    global $SAE_FS;
    return $SAE_FS->delete($domain, $filename);
}

function _sae_file_url($filename='', $domain=SAES3_DEFAULT_STORAGE){
    global $SAE_FS;
    return $SAE_FS->getUrl($domain, $filename);
}

function _sae_flock($file, $operation){
    $meta = stream_get_meta_data($file);
    $wrapper = $meta['wrapper_data'];
    if($wrapper instanceof SaeS3Stream){
        return $wrapper->stream_lock($operation);
    }else{
        trigger_error('***NOT SaeS3 file***');
        return flock($file, $operation);
    }
}

function saes3_error($data, $domain=SAES3_DEFAULT_STORAGE){
    $dump = var_export($data, true);
    file_put_contents("saes3://{$domain}/bug.log", $dump);
}

//put_content stat error? gz
//TODO: update stat after write?
//is_writable
//is_dir
//file_exists

//sae remove folder after last file removed


define('SAE_LOGGER', false);
/**
 * @see: http://www.php.net/manual/en/class.streamwrapper.php
 *
 */
class SaeS3Stream {
//        /* Properties */
//    public resource $context ;
//        /* Methods */
//    __construct ( void )
//    __destruct ( void )
//    public bool dir_closedir ( void )
//    public bool dir_opendir ( string $path , int $options )
//    public string dir_readdir ( void )
//    public bool dir_rewinddir ( void )
//    public bool rmdir ( string $path , int $options )
//    public bool stream_metadata ( int $path , int $option , int $var )
//    public bool stream_set_option ( int $option , int $arg1 , int $arg2 )

    const TYPE_FILE = 0100000;
    const TYPE_DIR  = 0040000;
    const ACCESS_MASK  = 0777;

    const dir_mode = 16895;     //040000 + 0222;
    const file_mode = 33279;    //0100000 + 0777;
    const folder_holder_filename = '.sae_folder_holder';

    protected static $locked_file = array();

    protected $position;
    protected $filename;
    protected $mode;
    protected $domain;
    protected $options;
    protected $opened_path;
    protected $stat = false;
    protected $content = null;
    protected $dirty = false;
    protected $enctype = 'plain';

    public function stream_open($url, $mode, $options, &$opened_path) {
        if(SAE_LOGGER){
            echo __FUNCTION__;
            var_dump($url);
            var_dump($mode);
            var_dump($options);
            var_dump($opened_path);
        }

        $parsed_url = parse_url($url);
        $this->domain = $parsed_url['host'];
        $this->filename = $parsed_url['path'] == '' ? '/' : $parsed_url['path'];
        $this->mode = $mode;
        $this->options = $options;
        $this->opened_path = $opened_path;
        $this->position = 0;
        $this->_sae_stat();
        $this->content = null;
        $this->dirty = false;

        //process open mode
        $IGNORED_MODE = array('b', '+');
        $mode = str_replace($IGNORED_MODE, $mode, '');
        if($mode == 'r' || $mode == 'c'){
            //do nothing
        }elseif($mode == 'w'){
            $this->stream_truncate(0);
        }elseif($mode == 'a'){
            $this->stream_seek(0, SEEK_END);
        }

        return true;
    }

    public function stream_read($count=-1){
        if(SAE_LOGGER){
            echo __FUNCTION__;
        }
        $this->_content();
        if($count == -1){
            return $this->content;
        }
        $ret = substr($this->content, $this->position, $count);
        $this->position += strlen($ret);
        return $ret;
    }

    public function stream_write($data){
        if(SAE_LOGGER){
            echo __FUNCTION__;
            echo 'write: '. $this->filename . ' ' . strlen($data) . "bytes\n";
        }

        $this->dirty = true;
        $this->_content();

        $length = strlen($data);
        $this->content =
            substr($this->content, 0, $this->position) .
                $data .
                substr($this->content, $this->position + $length);

        $this->position += $length;

        return $length;
    }

    public function stream_tell() {
        if(SAE_LOGGER){
            echo __FUNCTION__;
        }
        return $this->position;
    }
    protected function _length(){
        $length = $this->stat['length'];
        return $length;
    }
    public function stream_eof() {
        if(SAE_LOGGER){
            echo __FUNCTION__;
        }
        return $this->position >= $this->_length();
    }
    public function stream_seek($offset, $whence) {
        if(SAE_LOGGER){
            echo __FUNCTION__;
        }
        $length = $this->_length();
        switch ($whence) {
            case SEEK_SET: $newPos = $offset; break;
            case SEEK_CUR: $newPos = $this->position + $offset; break;
            case SEEK_END: $newPos = $length + $offset; break;
            default: return false;
        }


        if ($newPos >=0 && $newPos <= $length){
            $this->position = $newPos;
            return true;
        }else{
            return false;
        }
    }

    protected function _sae_stat($key=false){
        global $SAE_FS;
        $this->stat = $SAE_FS->getAttr($this->domain, $this->filename);

        return $key === false ? $this->stat : $this->stat[$key];
    }
    public function stream_stat(){
        if(SAE_LOGGER){
            echo __FUNCTION__;
            var_dump($this->filename);
        }

        global $SAE_FS;
        $file_mode = 0;
        $folder_holder = rtrim($this->filename, '/') . '/' . self::folder_holder_filename;
        if($SAE_FS->fileExists($this->domain, $folder_holder) || $this->filename === '/'){
            $file_mode = self::dir_mode;
        }

        if($SAE_FS->fileExists($this->domain, $this->filename)){
            $file_mode |= self::file_mode;
        }

        if($file_mode === 0){
            //should return false on non-existed
            return false;
        }

        $time = $this->stat['datetime'];
        $length = $this->_length();
        $file_stat = array(
            'dev' => 0,
            'ino' => 0,
            'mode' => $file_mode,
            'nlink' => 0,
            'uid' => 1,
            'gid' => 1,
            'rdev' => 0,
            'size' => $length, //size in bytes
            'atime' => $time, //time of last access (Unix timestamp)
            'mtime' => $time, //time of last modification (Unix timestamp)
            'ctime' => $time, //time of last inode change (Unix timestamp)
            'blksize' => -1,
            'blocks' => -1,
        );

        return array_merge(array_values($file_stat), $file_stat);
    }

    public function stream_close(){
        if(SAE_LOGGER){
            echo __FUNCTION__;
        }
        $this->domain = false;
        $this->filename = false;
        $this->mode = false;
        $this->options = false;
        $this->opened_path = false;
        $this->position = 0;
        $this->stat = false;
        $this->content = null;
        if($this->dirty === true){
            $this->stream_flush();
        }
        $this->dirty = false;
    }
    public function stream_cast($cast_as){
        if(SAE_LOGGER){
            echo __FUNCTION__;
        }
        return false;
    }
    public function stream_truncate($new_size){
        if(SAE_LOGGER){
            echo __FUNCTION__;
        }
        _sae_write_file($this->filename, '', $this->domain, -1, false);
        $this->dirty = false;
        $this->content = '';
        return true;
    }

    public static function url_stat($path, $flags){
        if(SAE_LOGGER){
            echo __FUNCTION__;
        }
        $stream = new self();
        $opt = '';
        $stream->stream_open($path, '', 0, $opt);
        return $stream->stream_stat();
    }

    public static function mkdir($path, $mode, $options){
        if(SAE_LOGGER){
            echo __FUNCTION__;
        }
        $parsed_url = parse_url($path);
        $domain = $parsed_url['host'];
        $dirname = rtrim($parsed_url['path'], '/') . '/';

//        $tmp_filename = '!!!tmp~' . rand(100000, 10000000) . '.temp';
//        echo $dirname . $tmp_filename . "\n";

        $tmp_filename = self::folder_holder_filename;
        $result = _sae_write_file($dirname . $tmp_filename, '', $domain);

        if($result){
            //sae remove folder once last file removed
            //_sae_remove_file($dirname . $tmp_filename, $domain);
        }

        return !!$result;
    }

    public static function unlink($path){
        if(SAE_LOGGER){
            echo __FUNCTION__;
        }
        $parsed_url = parse_url($path);
        $domain = $parsed_url['host'];
        $path = $parsed_url['path'];

        global $SAE_FS;
        return $SAE_FS->fileExists($domain, $path) && $SAE_FS->delete($domain, $path);
    }

    public function stream_flush(){
        if(SAE_LOGGER){
            echo __FUNCTION__;
        }
        $result = true;
        if($this->dirty && $this->content !== null){
            $result = _sae_write_file($this->filename, $this->content, $this->domain, -1, false);
        }

        if($result){
            $this->dirty = false;
        }

        return !!$result;
    }

    protected function _content(){
        if($this->content === null){
            $this->content = _sae_read_file($this->filename, $this->domain);
        }

        return $this->content;
    }


    public static function rename($path_from, $path_to){
        if(copy($path_from, $path_to)){
            return unlink($path_from);
        }

        return false;
    }
    public function stream_lock($operation){
        #flock() has been disabled for security reasons
        #returns NULL
        $lock_name = "{$this->domain}:{$this->filename}";
        $lock_var = null;
        if(isset($this->locked_file[$lock_name])){
            $lock_var = $this->locked_file[$lock_name];
        }
        if($operation == LOCK_UN){
            if($lock_var === null){
                return false;
            }
            unset($this->locked_file[$lock_name]);
            return true;
        }else{
            if($lock_var !== null){
                return false;
            }
            $this->locked_file[$lock_name] = true;
            return true;
        }
    }
}

Class SaeS3StreamGzip extends SaeS3Stream{
    protected $enctype = 'gzip';

    protected function _length(){
        $this->_content();
        return strlen($this->content);
    }

    private static function _gzdecode($data){
        // strip header and footer and inflate
        return gzinflate(substr($data, 10, -8));
    }

    public function stream_close(){
        parent::stream_close();
    }

    protected function _content(){
        if($this->content === null){
            $this->content = self::_gzdecode(parent::_content());
        }

        return $this->content;
    }

    public function stream_flush(){
        $result = true;
        if($this->dirty && $this->content !== null){
            $result = _sae_write_file($this->filename, $this->content, $this->domain);
        }

        if($result){
            $this->dirty = false;
        }
//        echo "stream_flushed!!!\n";
        return !!$result;
    }
}

stream_wrapper_register("saes3", "SaeS3Stream") or die('Failed to register protocol saes3://');
stream_wrapper_register("saes3gz", "SaeS3StreamGzip") or die('Failed to register protocol saes3gz://');
