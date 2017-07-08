<?php 
/**
 * phpsocket
 * 
 * @author shangheng
 */
error_reporting(E_ALL);
date_default_timezone_set('Asia/shanghai');

class PhpSocket
{
	// 日志目录
	const LOG_PATH = '/tmp';
	const LISTEN_SOCKET_NUM = 5;
	
	private $master;
	private $sockets = [];
	
	
	public function __construct($host,$port)
	{
		try {
			$this->master = socket_create(AF_INET, SOCK_STREAM, SOL_TCP);
			// 设置IP和端口重用,在重启服务器后能重新使用此端口;
			socket_set_option($this->master, SOL_SOCKET, SO_REUSEADDR, 1);
			// 将IP和端口绑定在服务器socket上;
			socket_bind($this->master, $host, $port);
			// listen函数使用主动连接套接口变为被连接套接口，使得一个进程可以接受其它进程的请求，从而成为一个服务器进程。
			// 在TCP服务器编程中listen函数把进程变为一个服务器，并指定相应的套接字变为被动连接,其中的能存储的请求不明的socket数目。
			socket_listen($this->master, self::LISTEN_SOCKET_NUM);
		}catch (\Exception $e){
			echo 1;exit;
		}

		$this->sockets[0] = ['resource'=>$this->master]; 
		// 获取该进程id
		$pid = posix_getpid();
		$this->writeLog(["server:{$this->master} startd,pid:{$pid}"]);
		
		while (true){
			try {
				$this->init();
			} catch (\Exception $e) {
				
			}
		}
	}
	
	private function init()
	{
		$write = $except = NULL;
		$sockets = array_column($this->sockets, 'resource');
		$read_num = socket_select($sockets, $write, $except, NULL);
		// select作为监视函数,参数分别是(监视可读,可写,异常,超时时间),返回可操作数目,出错时返回false;
		if (false === $read_num) {
			$this->error([
					'error_select',
					$errCode = socket_last_error(),
					socket_strerror($errCode)
			]);
			return;
		}
		
		foreach ($sockets as $socket){
			// 如果可读的是服务器socket,则处理连接逻辑
			if($socket == $this->master){
				$client = socket_accept($this->master);
				// 创建,绑定,监听后accept函数将会接受socket传来的连接,一旦有一个连接成功,将会返回一个新的socket资源用以交互,
				// 如果是一个多个连接的队列,只会处理第一个,如果没有连接的话,进程将会被阻塞,直到连接上.如果用set_socket_blocking或socket_set_noblock()设置了阻塞,会返回false;
				// 返回资源后,将会持续等待连接。
				if($client === false){
					$this->error(['socket_accept',$errCode = socket_last_error(),socket_strerror($errCode)]);
				}else{
					$this->connect($client);
					continue;
				}
			}else{// 如果可读的是其他已连接socket,则读取其数据,并处理应答逻辑
				$bytes = @socket_recv($socket, $buffer, 2048, 0);
				
				if($bytes < 9){
					$msg = $this->disconnect($socket);
				}else{
					if(!$this->sockets[(int)$socket]['handshake']){
						// 握手
						$this->handShake($socket, $buffer);
						continue;
					}else{
						$msg = $this->parse($buffer);
					}
				}
				
				array_unshift($msg, ['receive_msg']);
				$msg = $this->dealMsg($socket, $msg);
				$this->send($msg);
			}
		}
	}
	
	/**
	 * 客户端关闭连接
	 *
	 * @param $socket
	 *
	 * @return array
	 */
	private function disconnect($socket) {
		$recv_msg = [
				'type' => 'logout',
				'content' => $this->sockets[(int)$socket]['uname'],
		];
		socket_getpeername($socket, $ip, $port);
		$this->writeLog(['diconnect',$socket, $ip, $port]);
		unset($this->sockets[(int)$socket]);
		
		return $recv_msg;
	}
	
	/**
	 * 拼装信息
	 *
	 * @param $socket
	 * @param $recv_msg
	 *          [
	 *          'type'=>user/login
	 *          'content'=>content
	 *          ]
	 *
	 * @return string
	 */
	private function dealMsg($socket, $recv_msg) {
		$msg_type = $recv_msg['type'];
		$msg_content = $recv_msg['content'];
		$response = [];
	
		switch ($msg_type) {
			case 'login':
				$this->sockets[(int)$socket]['uname'] = $msg_content;
				// 取得最新的名字记录
				$user_list = array_column($this->sockets, 'uname');
				$response['type'] = 'login';
				$response['content'] = $msg_content;
				$response['user_list'] = $user_list;
				break;
			case 'logout':
				/* $user_list = array_column($this->sockets, 'uname');
				$response['type'] = 'logout';
				$response['content'] = $msg_content;
				$response['user_list'] = $user_list; */
				unset($this->sockets[(int)$socket]);
				$user_list = array_column($this->sockets, 'uname');
				$response['type'] = 'logout';
				$response['content'] = $msg_content;
				$response['user_list'] = $user_list;
				socket_getpeername($socket, $ip, $port);
				$this->writeLog(['diconnect',$socket, $ip, $port]);
				
				break;
			case 'user':
				$uname = $this->sockets[(int)$socket]['uname'];
				$response['type'] = 'user';
				$response['from'] = $uname;
				$response['content'] = $msg_content;
				break;
		}
	
		return $this->build(json_encode($response));
	}
	
	/**
	 * 发送消息
	 * 
	 */
	private function send($data)
	{
		$sockets = array_column($this->sockets, 'resource');
		foreach ($sockets as $socket){
			if(!($this->master == $socket)){
				socket_write($socket, $data, strlen($data));
			}
		}
	}
	
	/**
	 * 解析数据
	 *
	 * @param $buffer
	 *
	 * @return bool|string
	 */
	private function parse($buffer) {
		$decoded = '';
		$len = ord($buffer[1]) & 127;
		if ($len === 126) {
			$masks = substr($buffer, 4, 4);
			$data = substr($buffer, 8);
		} else if ($len === 127) {
			$masks = substr($buffer, 10, 4);
			$data = substr($buffer, 14);
		} else {
			$masks = substr($buffer, 2, 4);
			$data = substr($buffer, 6);
		}
		for ($index = 0; $index < strlen($data); $index++) {
			$decoded .= $data[$index] ^ $masks[$index % 4];
		}
	
		return json_decode($decoded, true);
	}
	
	/**
	 * 将socket添加到已连接列表,但握手状态留空;
	 */
	public function connect($socket)
	{
		socket_getpeername($socket, $ip, $port);
		$socketInfo = [
				'resource' => $socket,
				'uname' => '',
				'handshake' => false,
				'ip' => $ip,
				'port' => $port
		];
		$this->sockets[(int)$socket] = $socketInfo;
		$this->writeLog(array_merge(['socket_connect'],$socketInfo));
	}
	
	/**
	 * 握手
	 * 
	 */
	private function handShake($socket, $buffer)
	{
		// 获取客户端websocket密钥
		$webSocketKey = substr($buffer, strpos($buffer, 'Sec-WebSocket-Key:')+18);
		$webSocketKey = trim(substr($webSocketKey, 0, strpos($webSocketKey, "\r\n")));
		
		// 组装返回信息
		/* 服务端发送的成功的 Response 握手
		
		此握手消息是一个标准的HTTP Response消息，同时它包含了以下几个部分：
		状态行（如上一篇RFC2616中所述）
		Upgrade头域，内容为websocket
		Connection头域，内容为Upgrade
		Sec-WebSocket-Accept头域，其内容的生成步骤：
		a.首先将Sec-WebSocket-Key的内容加上字符串258EAFA5-E914-47DA-95CA-C5AB0DC85B11（一个UUID）。
		b.将#1中生成的字符串进行SHA1编码。
		c.将#2中生成的字符串进行Base64编码。
		Sec-WebSocket-Protocol头域（可选）
		Sec-WebSocket-Extensions头域（可选） */
		$upgradeKey = base64_encode(sha1($webSocketKey.'258EAFA5-E914-47DA-95CA-C5AB0DC85B11',true));
		$upgradeMessage = "HTTP/1.1 101 Switching Protocols\r\n";
		$upgradeMessage .= "Upgrade: websocket\r\n";
        $upgradeMessage .= "Sec-WebSocket-Version: 13\r\n";
        $upgradeMessage .= "Connection: Upgrade\r\n";
        $upgradeMessage .= "Sec-WebSocket-Accept:" . $upgradeKey . "\r\n\r\n";
        
        socket_write($socket, $upgradeMessage, strlen($upgradeMessage));
        $this->sockets[(int)$socket]['handshake'] = true;
        
        // 记录握手信息
        socket_getpeername($socket, $ip, $port);
        $this->writeLog(['handshake',$socket,$ip,$port]);
        
        $msg = [
        	'type' => 'handshake',
            'content' => 'done',
        ];
        
        $msg = $this->build(json_encode($msg));
        // 向客户端发送信息
        socket_write($socket, $msg, strlen($msg));
        return true;
	}
	
	/**
	 * 将普通信息组装成websocket数据帧
	 *
	 * @param $msg
	 *
	 * @return string
	 */
	private function build($msg) {
		$frame = [];
		$frame[0] = '81';
		$len = strlen($msg);
		if ($len < 126) {
			$frame[1] = $len < 16 ? '0' . dechex($len) : dechex($len);
		} else if ($len < 65025) {
			$s = dechex($len);
			$frame[1] = '7e' . str_repeat('0', 4 - strlen($s)) . $s;
		} else {
			$s = dechex($len);
			$frame[1] = '7f' . str_repeat('0', 16 - strlen($s)) . $s;
		}
	
		$data = '';
		$l = strlen($msg);
		for ($i = 0; $i < $l; $i++) {
			$data .= dechex(ord($msg{$i}));
		}
		$frame[2] = $data;
	
		$data = implode('', $frame);
	
		return pack("H*", $data);
	}
	
	/**
	 * 记录日志
	 * 
	 * @param array $info  
	 */
	private function writeLog(array $info)
	{
		$date = date('Y-m-d H:i:s',time());
		array_unshift($info, $date);
		
		$info = array_map('json_encode', $info);
		
		file_put_contents(self::LOG_PATH.'/phpsocket_log.log', implode(' | ', $info) . "\r\n",FILE_APPEND);
	}
	
	/**
	 * 记录错误
	 * 
	 */
	private function error(array $info)
	{
		$date = date('Y-m-d H:i:s',time());
		array_unshift($info, $date);
		
		$info = array_map('json_encode', $info);
		file_put_contents(self::LOG_PATH . '/phpsocket_error.log', implode('|', $info) . '\r\n',FILE_APPEND);
	}
}

new PhpSocket('192.168.10.2', 8080);

