<?php
require('function.get_userphoto_path.php');

/**
 * string userphoto(CTE, array)
 * 
 * @param string $scope
 * @param mixed[] $args
 */
function CTEPL__userphoto($scope, $args)
{
  extract($args, EXTR_REFS|EXTR_PREFIX_ALL, 'arg');
  
  // Check arguments.
  if(!isset($arg_uid))
    $CTE->throw_error('Missing argument <i>uid</i> for function <i>userphoto</i>.', ERR_USER_ERROR);
  if(!isset($arg_mode))
    $CTE->throw_error('Missing argument <i>mode</i> for function <i>userphoto</i>.', ERR_USER_ERROR);
  if(!isset($arg_gender))
    $CTE->throw_error('Missing argument <i>gender</i> for function <i>userphoto</i>.', ERR_USER_ERROR);
  
  echo CYCOM__get_userphoto_path($arg_uid, $arg_mode, $arg_gender);
}
?>