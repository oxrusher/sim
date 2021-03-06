<?php
/**
 * Copyright (c) 2015, ideawu
 * All rights reserved.
 * @author: ideawu
 * @link: http://www.ideawu.net/
 *
 * Sim PHP client SDK.
 */

class SimException extends Exception
{
}

class SimTimeoutException extends SimException
{
}

class SimClient
{
	private $debug = false;
	public $sock = null;
	private $_closed = false;
	private $recv_buf = '';
	public $last_resp = null;

	function __construct($host, $port, $timeout_ms=2000){
		$timeout_f = (float)$timeout_ms/1000;
		$this->sock = @stream_socket_client("$host:$port", $errno, $errstr, $timeout_f);
		if(!$this->sock){
			throw new SimException("$errno: $errstr");
		}
		if($timeout_ms > 0){
			$timeout_sec = intval($timeout_ms/1000);
			$timeout_usec = ($timeout_ms - $timeout_sec * 1000) * 1000;
			@stream_set_timeout($this->sock, $timeout_sec, $timeout_usec);
		}
		if(function_exists('stream_set_chunk_size')){
			@stream_set_chunk_size($this->sock, 1024 * 1024);
		}
	}
	
	function close(){
		if(!$this->_closed){
			@fclose($this->sock);
			$this->_closed = true;
			$this->sock = null;
		}
	}

	function closed(){
		return $this->_closed;
	}

	function send($data){
		if(is_array($data)){
			$s = self::encode_msg($data);
		}else{
			$s = $data;
		}
		if($this->debug){
			echo '> ' . str_replace(array("\r", "\n"), array('\r', '\n'), $s) . "\n";
		}
		try{
			while(true){
				$ret = @fwrite($this->sock, $s);
				if($ret === false){
					$this->close();
					throw new SimException('Connection lost');
				}
				$s = substr($s, $ret);
				if(strlen($s) == 0){
					break;
				}
				@fflush($this->sock);
			}
		}catch(Exception $e){
			$this->close();
			throw new SimException($e->getMessage());
		}
		return $ret;
	}
	
	function recv(){
		while(true){
			$ret = $this->parse();
			if($ret !== null){
				return $ret;
			}
			try{
				$data = @fread($this->sock, 1024 * 1024);
				if($this->debug){
					echo '< ' . str_replace(array("\r", "\n"), array('\r', '\n'), $data) . "\n";
				}
			}catch(Exception $e){
				$data = '';
			}
			if($data === false || $data === ''){
				if(feof($this->sock)){
					$this->close();
					throw new SimException('Connection lost');
				}else{
					throw new SimTimeoutException('Connection timeout');
				}
			}
			$this->recv_buf .= $data;
#			echo "read " . strlen($data) . " total: " . strlen($this->recv_buf) . "\n";
		}
	}
	
	private function parse(){
		$msg_end = strpos($this->recv_buf, "\n");
		if($msg_end === false){
			return null;
		}
		$line = substr($this->recv_buf, 0, $msg_end + 1);
		$this->recv_buf = substr($this->recv_buf, $msg_end + 1);
		return $this->decode_msg($line);
		/*
		// TODO:
		if(isset($ret[SIM_LIST_TAG])){
			$list = parse_msgs_in_str($ret[SIM_LIST_TAG]);
			$ret = $list;
		}
		if(isset($ret[SIM_MAP_TAG])){
			$list = parse_msgs_in_str($ret[SIM_MAP_TAG]);
			$map = ... $list...
			$ret = $map;
		}
		*/
		return $ret;
	}
	
	static function decode_msg($line){
		$line = trim($line);
		$ret = array();
		$auto_tag = 0;
		$kvs = explode(' ', $line);
		foreach($kvs as $kv){
			$ps = explode('=', $kv, 2);
			if(count($ps) == 1){
				$tag = $auto_tag;
				$val = $ps[0];
			}else{
				$tag = intval($ps[0]);
				$val = $ps[1];
			}
			$auto_tag = $tag + 1;
			$val = self::decode($val);
			$ret[$tag] = $val;
		}
		return $ret;
	}

	static function encode_msg($data){
		$ps = array();
		foreach($data as $k=>$v){
			$k = intval($k);
			if(is_array($v)){
				$v = self::encode_msg($v);
				$v = self::encode($v);
			}else{
				$v = self::encode($v);
			}
			$ps[] = "$k=$v";
		}
		$s = join(' ', $ps) . "\n";
		return $s;
	}
		
	static function encode($data){
		static $min_c = 0;
		if($min_c == 0){
			$min_c = ord('!');
		}
		static $max_c = 0;
		if($max_c == 0){
			$max_c = ord('~');
		}
		
		$str = $data;
		$ret = '';
		$len = strlen($str);
		for($i=0; $i<$len; $i++){
			$c = $str[$i];
			switch($c){
				case ' ':
					$ret .= '\\s';
					break;
				case "\\":
					$ret .= "\\\\";
					break;
				case "\a":
					$ret .= "\\a";
					break;
				case "\b":
					$ret .= "\\b";
					break;
				case "\f":
					$ret .= "\\f";
					break;
				case "\v":
					$ret .= "\\v";
					break;
				case "\r":
					$ret .= "\\r";
					break;
				case "\n":
					$ret .= "\\n";
					break;
				case "\t":
					$ret .= "\\t";
					break;
				case "\0":
					$ret .= "\\0";
					break;
				default:
					$ret .= $c;
					// TODO: 对非 UTF-8 字符进行转义
					/*
					$ord = ord($c);
					if($ord >= $min_c && $ord <= $max_c){
						$ret .= $c;
					}else{
						$ret .= "\\x";
						$ret .= sprintf('%02x', $ord);
					}
					*/
					break;
			}
		}
		return $ret;
	}
	
	static function decode($str){
		$ret = '';
		$len = strlen($str);
		for($i=0; $i<$len; $i++){
			$c = $str[$i];
			if($c != '\\'){
				$ret .= $c;
				continue;
			}
			if($i >= $len - 1){
				break;
			}
			$c2 = $str[++$i];
			switch($c2){
				case 's':
					$ret .= ' ';
					break;
				case '\\':
					$ret .= "\\";
					break;
				case 'a':
					$ret .= "\a";
					break;
				case 'b':
					$ret .= "\b";
					break;
				case 'f':
					$ret .= "\f";
					break;
				case 'v':
					$ret .= "\v";
					break;
				case 'r':
					$ret .= "\r";
					break;
				case 'n':
					$ret .= "\n";
					break;
				case 't':
					$ret .= "\t";
					break;
				case '0':
					$ret .= "\0";
					break;
				case 'x':
					$hex = substr($str, $i+1, 2);
					$ret .= hex2bin($hex);
					$i += 2;
					break;
				default:
					$ret .= $c2;
					break;
			}
		}
		return $ret;
	}
}	
