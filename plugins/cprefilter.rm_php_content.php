<?php

/**
 * string rm_php_content(CTE, array)
 * Wraps PHP-content in CTE-comment tags. The reason for this is that CTE_Compiler would like to count 
 * when and how many rows will disappear. Without this the at-line-tracking will fail.
 *
 * @param CTE $cte
 * @param string $string
 */
function CTEPL__rm_php_content($string)
{
  $string = preg_replace('/('.
                              '<\?'.                  // PHP start-tag
                              '(?!xml)'.              // Not followed by "xml"
                              '(?:(?:php)|(?:\=))?'.  // Followed by "php", "=" or nothing.
                              '.*?'.                  // Any PHP content.
                              '\?>'.                  // PHP end-tag.
                              ')/ism', '{*$1*}', $string);
  $string = preg_replace('/(<script .*?(?:(?:type="?text\/php"?).*?>(?:.|\n|\r)*?(?:<\/script>)))/i', '{*$1*}', $string);
  
  return $string;
}
?>