<?php
/**
 * string genderage(CTE, array)
 * 
 *
 * @param CTE $CTE
 * @param mixed[] $args
 */
function CTEPL__genderage($scope, $args)
{
  foreach($args as $key => $value)
  {
    echo $key.' contains: '.$value.'<br />';
  }
  exit;
}
?>