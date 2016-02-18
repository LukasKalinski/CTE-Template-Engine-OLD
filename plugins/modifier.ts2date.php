<?php
/**
 * mixed ts2date(string, string [, string[]])
 * 
 *
 * @param mixed $timestamp
 */
function CTEPL__ts2date($timestamp, $format, $months=null, $lang_today=null, $lang_yesterday=null)
{
  $is_current_year = (date('Y', $timestamp) == date('Y'));
  $prefix = '';
  $format = preg_replace('/\((.*)Y(.*)\)\?/', ($is_current_year ? '' : '\1Y\2'), $format);
  
  if($is_current_year && date('n') == date('n', $timestamp))
  {
    if(!is_null($lang_today) && (int)date('j') == date('j', $timestamp))
    {
      $prefix = $lang_today;
    }
    elseif(!is_null($lang_yesterday) && (int)date('j')-1 == date('j', $timestamp))
    {
      $prefix = $lang_yesterday;
    }
    
    if(!empty($prefix))
    {
      $format = preg_replace('/d|j|D|l/', '', $format);
      $format = preg_replace('/F|m|M|n/', '', $format);
    }
  }
  
  $month_format = null;
  // Month full-string representation.
  if(strpos($format, 'F') !== false)
  {
    $month_str = $months[(int)date('n', $timestamp)];
    $month_format = 'F';
  }
  // Month short-string representation.
  elseif(strpos($format, 'M') !== false)
  {
    $month_str = substr($months[(int)date('m', $timestamp)], 0, 3);
    $month_format = 'F';
  }
  
  if(isset($month_str))
    $format = str_replace($month_format, addcslashes($month_str, 'A..z'), $format);
  
  return $prefix.date($format, $timestamp);
}
?>