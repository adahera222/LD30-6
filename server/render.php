<?php

function render_requests($user,$input){
  update_user($user);
  if(is_array($input->requests)){
    $ret = array();
    foreach($input->requests as $key => $request){
      $ret[$key] = render_request($user,$request);
    }
    return $ret;
  } else {
    return array();
  }
}

function render_request($user,$request){
  if(is_string($request->func)){
    $fname = "api_".$request->func;
    if(function_exists($fname)){
      return $fname($user,isset($request->args) ? $request->args : array() );
    } else {
      return make_error("Function not availible: ".$fname);
    }
  }
}

function default_user($user){
  $user->ap = isset($user->ap) ? $user->ap : 100;
  $user->ap_update = isset($user->ap_update) ? $user->ap_update : time();
  $user->ap_max = isset($user->ap_max) ? $user->ap_max : 100;
  $user->x = isset($user->x) ? $user->x : 0;
  $user->y = isset($user->y) ? $user->y : 0;
  $user->z = isset($user->z) ? $user->z : 0;
  $user->warp_eta = isset($user->warp_eta) ? $user->warp_eta : 0;
  $user->warp_range = isset($user->warp_range) ? $user->warp_range : 10;
  $user->speed = isset($user->speed) ? $user->speed : 1;
  $user->scan_range = isset($user->scan_range) ? $user->scan_range : 10;
  return $user;
}

function dist($a,$b){
  return sqrt(
    pow($a->x - $b->x,2) +
    pow($a->y - $b->y,2) +
    pow($a->z - $b->z,2)
  );
}

function make_error($s){
  $ret = new stdClass();
  $ret->error = array($s);
  return $ret;
}

function update_user($user){
  $dbsave = false;
  $duser = default_user($user);
  if($duser->warp_eta <= time()){
    $user->warp_eta = 0;
    $dbsave = true;
  }
  $new_ap = time() - $user->ap_update;

  if($new_ap > 0){
    $user->ap += $new_ap;
    if($user->ap > $user->ap_max){
      $user->ap = $user->ap_max;
    }
    $user->ap_update = time();
    $dbsave = true;
  }
  if($dbsave){
    dbsave();
  }
}

function api_getuser($user,$args){
  $duser = default_user($user);
  $ruser = new stdClass();
  $ruser->x = $duser->x;
  $ruser->y = $duser->y;
  $ruser->z = $duser->z;
  $ruser->warp_eta = $duser->warp_eta;
  $ruser->warp_range = $duser->warp_range;
  $ruser->scan_range = $duser->scan_range;
  $ruser->speed = $duser->speed;
  $ruser->ap = $duser->ap;
  $ruser->ap_max = $duser->ap_max;
  $ret = new stdClass();
  $ret->ret = $ruser;
  return $ret;
}

function api_getusers($user,$args){
  global $db;
  $duser = default_user($user);
  if($duser->warp_eta > 0){
    return array();
  }
  $list = array();
  foreach($db->users as $dbuser){

    $ddbuser = default_user($dbuser);
    if(
      $ddbuser->name != $duser->name and
      $ddbuser->warp_eta == 0 and
      dist($ddbuser,$duser) <= $duser->scan_range
    ){
      $ruser = new stdClass();
      $ruser->name = $ddbuser->name;
      $ruser->x =$ddbuser->x;
      $ruser->y =$ddbuser->y;
      $ruser->z =$ddbuser->z;
      $list[] = $ruser; 
    }
    
  }
  $ret = new stdClass();
  $ret->ret = $list;
  return $ret;
}

function api_move($user,$args){
  $duser = default_user($user);
  if($duser->warp_eta > 0){
    return make_error("You are currently in warp.");
  }
  $valid = true;
  if(!isset($args->x) or !is_int($args->x)){$valid = false; }
  if(!isset($args->y) or !is_int($args->y)){$valid = false; }
  if(!isset($args->z) or !is_int($args->z)){$valid = false; }

  if($valid){

    if(
      $duser->x == $args->x and 
      $duser->y == $args->y and 
      $duser->z == $args->z){
      return make_error("You are already at this location.");
    }

    $dist = dist($args,$duser);
    if($dist <= $duser->warp_range){

      $dist_cost = ceil($dist/$duser->speed);
      $ap_cost = $dist_cost*10;
      if($ap_cost > $user->ap){
        return make_error("Not enough AP. (Need ".$ap_cost.")");
      }
      $user->ap -= $ap_cost;

      $user->warp_eta = time() + $dist_cost;
      $user->x = $args->x;
      $user->y = $args->y;
      $user->z = $args->z;
      dbsave();
      return new stdClass();
    } else {
      return make_error("You cannot travel more than ".$duser->warp_range." units.");
    }

  } else {
    return make_error("Invalid arguments.");
  }

}

function api_attack($user,$args){
  global $db;
  $duser = default_user($user);
  if(!isset($args->target)or !is_string($args->target)){
    return make_error("Invalid arguments.");
  }
  $valid_target = false;
  foreach($db->users as $dbuser){
    $ddbuser = default_user($dbuser);
    if($ddbuser->name == $args->target){
      if($duser->x == $ddbuser->x and
        $duser->y == $ddbuser->y and
        $duser->z == $ddbuser->z){
        $valid_target = true;
        break;
      } else {
        return make_error("Target no longer at locaton.");
      }
    }
  }
  if($valid_target){
    return make_error("TODO: Attack!");
  } else {
    return make_error("Invalid target.");
  }
}

function api_sendchat($user,$args){

  global $db;
  if(!isset($args->msg) or !is_string($args->msg)){
    return make_error("Invalid arguments.");
  }
  if($args->msg == ""){
    return make_error("No message to send.");
  }
  $msgobj = new stdClass();
  $msgobj->name = $user->name;
  $msgobj->time = microtime(true);
  $msgobj->data = substr($args->msg,0,128);

  if(!isset($db->chat)){
    $db->chat = array();
  }
  $db->chat[] = $msgobj;
  if(count($db->chat) > 10){
    array_shift($db->chat);
  }

  dbsave();
  return new stdClass();
  
}

function api_getchat($user,$args){
  global $db;
  if(!isset($args->time) or !is_int($args->time)){
    return make_error("Invalid arguments.");
  }
  $ret = new stdClass();
  $ret->ret = array();
  if(isset($db->chat)){
    foreach($db->chat as $msg){
      if(floor($msg->time)+1 >= $args->time and $user->name != $msg->name){
        $ret->ret[] = $msg;
      }
    }
  }
  array_reverse($ret->ret);
  return $ret;
}
