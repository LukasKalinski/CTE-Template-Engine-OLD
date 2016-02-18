<?php
/********************************************************************************\
 * File:          cte/engine/plugins/lib/lib.btngen.php
 * Description:   Button generator library
 * Begin:         2005-04-08
 * Edit:          2006-03-19
 * Author:        Lukas Kalinski
 * Copyright:     2005- CyLab Sweden
\********************************************************************************/

require_once('system/lib.error.php');
require_once('system/db/class.DB_Lang_Retriever.php');
require_once('function.uniord.php');
require_once('function.unichr.php');

// Set transparent color to FF00FF:
define('COLOR_TRANSPARENT_R', 255);
define('COLOR_TRANSPARENT_G', 0);
define('COLOR_TRANSPARENT_B', 255);

class Image_Text
{
  const CSET_ENTITY_SPACING        = 1;
  const CSET_UCHARS_POS_Y          = 0;
  const CSET_LCHARS_POS_Y          = 13;
  const CSET_SCHARS_POS_Y          = 26;
  const CSET_ADD_UCHARS_POS_Y      = 39;
  const CSET_ADD_LCHARS_POS_Y      = 52;
  const CSET_CHAR_HEIGHT           = 12;
  
  const CSET_COLOR_TEXT_R          = 255;  // == #FFFFFF
  const CSET_COLOR_TEXT_G          = 255;
  const CSET_COLOR_TEXT_B          = 255;
  
  /**
   * Standard character x-size.
   * ASCII < 127 chars.
   */
  private $std_ucharxs = array('A' => 7, 'B' => 5, 'C' => 6, 'D' => 6,   // Upper case
                               'E' => 4, 'F' => 5, 'G' => 6, 'H' => 6,
                               'I' => 3, 'J' => 4, 'K' => 6, 'L' => 5,
                               'M' => 7, 'N' => 5, 'O' => 7, 'P' => 5,
                               'Q' => 7, 'R' => 6, 'S' => 5, 'T' => 7,
                               'U' => 6, 'V' => 7, 'W' => 9, 'X' => 5,
                               'Y' => 7, 'Z' => 5);
  private $std_lcharxs = array('a' => 5, 'b' => 5, 'c' => 4, 'd' => 5,   // Lower case
                               'e' => 5, 'f' => 4, 'g' => 5, 'h' => 5,
                               'i' => 1, 'j' => 3, 'k' => 5, 'l' => 1,
                               'm' => 9, 'n' => 5, 'o' => 5, 'p' => 5,
                               'q' => 5, 'r' => 3, 's' => 4, 't' => 4,
                               'u' => 5, 'v' => 4, 'w' => 5, 'x' => 5,
                               'y' => 4, 'z' => 4);
  private $std_scharxs = array(' ' => 3, '+' => 5, '-' => 5, '!' => 1,   // Special chars
                               '_' => 5, '?' => 4);
  
  /**
   * @var array
   * Additional character x-size. [ASCII > 127 and non-ASCII chars.]
   */
  private $add_ucharxs = null;
  private $add_lcharxs = null;
  
  private $img_charset = null;
  private $cdata = array();
  private $text_image = null;
  private $text_image_width = null;
  private $current_text_color = array('r' => null, 'g' => null, 'b' => null);
  
  /**
   * Constructor
   */
  public function __construct($charset_file)
  {
    // Validate and initiate charset image file:
    if(!file_exists($charset_file))
      $this->trigger_error('Could not find charset image file <i>'.$charset_file.'</i>.', __LINE__, ERR_USER_ERROR);
    elseif(!is_readable($charset_file))
      $this->trigger_error('Permission denied to charset image file <i>'.$charset_file.'</i>.', __LINE__, ERR_USER_ERROR);
    
    $this->img_charset = imagecreatefromgif($charset_file); // Open charset file.
    
    // Convert char-keys to unicode number-keys:
    $this->unikeys($this->std_ucharxs);
    $this->unikeys($this->std_lcharxs);
    $this->unikeys($this->std_scharxs);
    
    // Load additional characters from database:
    $dblang = new DB_Lang_Retriever();
    $chars = $dblang->get_unichar_list('unidec_ucase,unidec_lcase,imgchar_ucase_width,imgchar_lcase_width');
    $dblang->destroy();
    
    for($i=0, $ii=count($chars); $i<$ii; $i++)
    {
      if($chars[$i]['imgchar_ucase_width'] != null && $chars[$i]['imgchar_lcase_width'] != null)
      {
        $this->add_ucharxs[$chars[$i]['unidec_ucase']] = $chars[$i]['imgchar_ucase_width'];
        $this->add_lcharxs[$chars[$i]['unidec_lcase']] = $chars[$i]['imgchar_lcase_width'];
      }
    }
    
    // ## IMPORTANT NOTE ABOUT CHARACTER MAP IMAGE:
    // ## - Additional characters are ordered by theird unicode number.
    // ## Therefore we're sorting corresponding arrays (by key) here:
    ksort($this->add_ucharxs);
    ksort($this->add_lcharxs);
    
    // Load image charset lines:
    $this->load_charset_line($this->add_ucharxs, self::CSET_ADD_UCHARS_POS_Y);
    $this->load_charset_line($this->add_lcharxs, self::CSET_ADD_LCHARS_POS_Y);
    $this->load_charset_line($this->std_ucharxs, self::CSET_UCHARS_POS_Y);
    $this->load_charset_line($this->std_lcharxs, self::CSET_LCHARS_POS_Y);
    $this->load_charset_line($this->std_scharxs, self::CSET_SCHARS_POS_Y);
  }
  
  /**
   * @desc Converts keys from characters to their unicode numbers.
   * @param array &$list
   * @return void
   */
  private function unikeys(&$list)
  {
    $tmp_chars = array();
    foreach($list as $key => $value)
      $tmp_chars[Cylib__uniord($key)] = $value;
    $list = $tmp_chars;
  }
  
  /**
   * @param string $color_hex
   * @return Image
   */
  public function get_text_image($color_hex=null)
  {
    // Set text color if requested:
    if($color_hex !== null)
      $this->apply_text_color($color_hex);
    
    return $this->text_image;
  }
  
  public function get_text_image_width()   { return $this->text_image_width; }
  public function get_text_image_height()  { return self::CSET_CHAR_HEIGHT; }
  
  /**
   * @param string $message
   * @param integer $line
   * @param string $err_type
   * @return void
   */
  private function trigger_error($message, $line, $err_type)
  {
    CYCOM__trigger_error('<b># class Image_Text:</b><br />'.$message.'<br />'.
                         'On line '.$line.' in file '.basename(__FILE__), __FILE__, $line, $err_type, false, true);
  }
  
  /**
   * @param array $char_data     # Character data source. array(char => width)
   * @param integer $y_pointer   # The character line (y) pointer.
   * @return void
   */
  private function load_charset_line($char_data, $y_pointer)
  {
    $x_pointer = 0;
    foreach($char_data as $char_code => $char_width)
    {
      $this->cdata[$char_code]['w'] = $char_width;
      $this->cdata[$char_code]['img'] = imagecreate($char_width, self::CSET_CHAR_HEIGHT);
      
      imagecopy($this->cdata[$char_code]['img'], $this->img_charset, 0, 0, $x_pointer, $y_pointer, $char_width, self::CSET_CHAR_HEIGHT);
      $x_pointer += (self::CSET_ENTITY_SPACING + $char_width);
    }
  }
  
  /**
   * @param string $color_hex
   * @return int
   */
  private function parse_hex_color($color_hex)
  {
    if(strlen($color_hex) != 6) return;
    
    $colors = str_split($color_hex, 2);
    
    return array('r' => hexdec($colors[0]),
                 'g' => hexdec($colors[1]),
                 'b' => hexdec($colors[2]));
  }
  
  /**
   * @param string $c Hex color value.
   * @return void
   */
  private function apply_text_color($c)
  {
    if(strlen($c) == 6)
    {
      $c = $this->parse_hex_color($c);
      
      if($this->current_text_color['r'] === null)
        $actual_text_index = imagecolorresolve($this->text_image,
                                               self::CSET_COLOR_TEXT_R,
                                               self::CSET_COLOR_TEXT_G,
                                               self::CSET_COLOR_TEXT_B);
      else
        $actual_text_index = imagecolorresolve($this->text_image,
                                               $this->current_text_color['r'],
                                               $this->current_text_color['g'],
                                               $this->current_text_color['b']);
      
      $this->current_text_color = $c;
      imagecolorset($this->text_image, $actual_text_index, $c['r'], $c['g'], $c['b']);
    }
    else
    {
      $this->trigger_error('Invalid color supplied for apply_text_color().', __LINE__, ERR_USER_WARNING);
    }
  }
  
  /**
   * @param string $text
   * @return void
   */
  private function calc_text_width($text)
  {
    // Calculate image width:
    $this->text_image_width = -1; // So we don't have to remove the overflowing entry spacer width later.
    
    for($i=0, $ii=mb_strlen($text); $i<$ii; $i++)
    {
      $char_code = Cylib__uniord(mb_substr($text, $i, 1));
      
      if(key_exists($char_code, $this->cdata))
        $this->text_image_width += ($this->cdata[$char_code]['w'] + self::CSET_ENTITY_SPACING);
    }
    
    if($this->text_image_width < 1)
      $this->trigger_error('Text image evaluated to < 1 px.', __LINE__, ERR_SYSTEM_ERROR);
  }
  
  /**
   * @desc Generates and returns text image on success; -1 on failure.
   * @param string $text
   * @param string $color_hex
   * @return void
   */
  public function generate($text, $color_hex=null)
  {
    if(empty($text))
    {
      $this->trigger_error('Text string was empty.', __LINE__, ERR_SYSTEM_WARNING);
      return imagecreate(5,5);
    }
    
    $this->calc_text_width($text);
    $this->text_image = imagecreate($this->text_image_width, self::CSET_CHAR_HEIGHT);
    $c_transparent = imagecolorallocate($this->text_image, COLOR_TRANSPARENT_R, COLOR_TRANSPARENT_G, COLOR_TRANSPARENT_B);
    
    // Build text string:
    $x_pointer = 0;
    for($i=0, $ii=mb_strlen($text); $i<$ii; $i++)
    {
      $char_code = Cylib__uniord(mb_substr($text, $i, 1));
      
      if(!key_exists($char_code, $this->cdata))
      {
        $this->trigger_error('Unknown character found: &#'.$char_code.';', __LINE__, ERR_SYSTEM_WARNING);
        continue;
      }
      
      imagecopymerge($this->text_image, $this->cdata[$char_code]['img'], $x_pointer, 0, 0, 0, $this->cdata[$char_code]['w'], self::CSET_CHAR_HEIGHT, 100);
      $x_pointer += ($this->cdata[$char_code]['w'] + self::CSET_ENTITY_SPACING);
    }
    
    imagecolortransparent($this->text_image, $c_transparent);
    
    if($color_hex !== null)
      $this->apply_text_color($color_hex);
  }
}

class Button
{
  private $image_file = array('L' => null, 'C' => null, 'R' => null);
  private $text_pos_y = null;
  private $left_token_width = -1;
  private $center_width = -1;
  private $right_token_width = -1;
  
  /**
   * @param string $btnimg_left
   * @param string $btnimg_center
   * @param string $btnimg_right
   * @param int $valign_top
   */
  public function __construct($btnimg_left, $btnimg_center, $btnimg_right, $valign_top)
  {
    $this->image_file['L'] = $btnimg_left;
    $this->image_file['C'] = $btnimg_center;
    $this->image_file['R'] = $btnimg_right;
    
    // Validate paths:
    foreach($this->image_file as $token => $filepath)
    {
      if(!file_exists($filepath))
        $this->trigger_error('Could not find file <i>"'.$filepath.'"</i>.', __LINE__, ERR_USER_ERROR);
      elseif(!is_readable($filepath))
        $this->trigger_error('Permission denied to file <i>'.$filepath.'</i>.', __LINE__, ERR_SYSTEM_ERROR);
    }
    
    $this->text_pos_y = $valign_top;
  }
  
  /**
   * @param string $message
   * @param integer $line
   * @param string $err_type
   * @return void
   */
  private function trigger_error($message, $line, $err_type)
  {
    CYCOM__trigger_error('<b># class Button_Image:</b><br />'.$message.'<br />'.
                         'On line '.$line.' in file '.basename(__FILE__), __FILE__, $line, $err_type, false, true);
  }
  
  /**
   * @desc Imports widths of left and right tokens into corresponding class variables and
   *       returns an array with token Image resources and their relevant properties (right now width and height).
   * @return array(L=>[obj|w|h], C=>[obj|w|h], R=>[obj|w|h])
   */
  private function import_token_data()
  {
    $img = array('L' => null, 'C' => null, 'R' => null);
    foreach($this->image_file as $pos => $src)
    {
      $img[$pos]['obj'] = imagecreatefromgif($src);
      $img[$pos]['w']   = imagesx($img[$pos]['obj']);
      $img[$pos]['h']   = imagesy($img[$pos]['obj']);
    }
    $this->left_token_width = $img['L']['w'];
    $this->right_token_width = $img['R']['w'];
    
    return $img;
  }
  
  /**
   * @desc Builds button image and returns it as a resource.
   * @param string $charset_image
   * @param string $text_string
   * @param string $text_color
   * @param string $text_shadow_color
   * @return Image resource
   */
  public function build($charset_image, $text_string, $text_color, $text_shadow_color=null)
  {
    // ## Initiate button image tokens.
    $img = $this->import_token_data();
    
    // ## Create text object.
    $T_IMG = new Image_Text($charset_image);
    $T_IMG->generate($text_string, $text_color);
    $img['text']['w'] = $T_IMG->get_text_image_width();
    // Increase width by 1 if we have a shadow.
    if($text_shadow_color !== null)
      $img['text']['w']++;
    $this->center_width = $img['text']['w'];
    
    
    // ## Initiate button image area.
    $btn_image_width  = $img['L']['w'] + $img['text']['w'] + $img['R']['w'];
    $btn_image_height = $img['C']['h'];
    
    $btn_image = imagecreate($btn_image_width, $btn_image_height);
    $c_transparent = imagecolorallocate($btn_image, COLOR_TRANSPARENT_R, COLOR_TRANSPARENT_G, COLOR_TRANSPARENT_B);
    
    // ## Build button body.
    // Add button left image:
    imagecopy($btn_image, $img['L']['obj'], 0, 0, 0, 0, $img['L']['w'], $img['L']['h']);
    
    // Add button center image:
    $x_pointer = null;
    for($current_center_width=0; $current_center_width<$img['text']['w']; $current_center_width+=$img['C']['w'])
    {
      $width = min(abs($current_center_width-$img['text']['w']), $img['C']['w']);
      $x_pointer = $img['L']['w'] + $current_center_width;
      imagecopy($btn_image, $img['C']['obj'], $x_pointer, 0, 0, 0, $width, $btn_image_height);
    }
    
    // Add button right image:
    $x_pointer = $img['L']['w'] + $img['text']['w'];
    imagecopy($btn_image, $img['R']['obj'], $x_pointer, 0, 0, 0, $img['R']['w'], $img['R']['h']);
    
    // Add text shadow if isset:
    if($text_shadow_color !== null)
    {
      imagecopy($btn_image,
                $T_IMG->get_text_image($text_shadow_color),
                ($img['L']['w']+1),
                $this->text_pos_y+1,
                0,
                0,
                $img['text']['w'],
                $T_IMG->get_text_image_height());
    }
    
    // Add transparency.:
    imagecolortransparent($btn_image, $c_transparent);
    
    // Add button label:
    imagecopy($btn_image, $T_IMG->get_text_image($text_color), $img['L']['w'], $this->text_pos_y, 0, 0, $img['text']['w'], $T_IMG->get_text_image_height());
    
    return $btn_image;
  }
  
  /**
   * @param int $of
   * @return int
   */
  public function get_token_size($of)
  {
    $this->import_token_data();
    
    switch($of)
    {
      case 1: return $this->left_token_width;
      case 2: return $this->center_width;
      case 3: return $this->right_token_width;
    }
  }
}
?>