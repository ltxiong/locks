<?php
namespace Ltxiong\Locks;


/**
 * 工具类封装
 * 
 */
class Tool
{

    public function __construct()
    {
    }

    /**
     * 格式化get参数列表，相应参数值会进行urlencode转码，所以在参数接收方获取到参数值时需要做一次urldecode解码
     *
     * @param array $data 参数列表
     * @return string 
     */
	private function formatGetData(array $data = array())
	{
		if(empty($data))
		{
			return '';
        }
		$get_array = array();
		foreach ($data as $k => $v)
		{
            // 对参数进行必要的urlencode转码处理
			$get_array[] = $k . '=' . urlencode($v);
		}
		return implode('&', $get_array);
    }

    /**
     * 发送网络请求
     *
     * @param string $req_url 请求url
     * @param string $request_method 请求方式 get/post
     * @param array $data 请求时所带参数列表
     * @param array $req_args 请求额外参数列表，参数和格式见如下所示
     * $req_args可传递的参数列表与说明
     *   $req_args['headers']  type:array 请求头header信息数组
     *   $req_args['return_json']  type:int 响应结果是否返回json格式，0：返回原始值，1：返回json格式
     *   $req_args['time_out']  type:int 超时时间，单位为秒，例如：3，如不传默认值为1
     * 
     * @return array 返回的响应结果，返回结果参数列表如下
     *   http_code type:int 响应的http状态码，例如，200/404/403
     *   http_errno type:int 错误码
     *   http_error type:string 详细的错误内容
     *   data type:array 返回的响应结果，例如： 'data' => array('resp_rs' => $http_response), 
     *   data当中数据结构为
     *     resp_rs type:string 返回的原始响应结果
     *     resp_rs type:array 当参数$req_args['return_json']为1时，此结果为原始响应结果进行json_decode后的json结果返回
     */
    public function httpSender(string $req_url, string $request_method = 'get', array $data = [], $req_args = [])
	{
        if (!extension_loaded("curl")) throw new Exception('Please Open Curl Module！', E_USER_DEPRECATED);
        // 请求超时时间设置 
        $time_out = isset($req_args['time_out']) ? intval($req_args['time_out']) : 3;
        if(empty($time_out))
        {
            $time_out = 1;
        }
        $return_json = isset($req_args['return_json']) ? intval($req_args['return_json']) : 0;

        // header 头部处理
        $headers = isset($req_args['headers']) && is_array($req_args['headers']) ? $req_args['headers'] : array();

		// 初始化请求
		$ch = curl_init();
        curl_setopt($ch, CURLOPT_HEADER, 0);
        // TRUE 将curl_exec()获取的信息以字符串返回，而不是直接输出。
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
        // 在尝试连接时等待的秒数。设置为0，则无限等待。
		curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 3);
        curl_setopt($ch, CURLOPT_TIMEOUT, $time_out);
        $request_url = $req_url;
        $post_header_arr = array();
		// get请求
		if ($request_method === 'get')
		{
			$data_string = $this->formatGetData($data);
			$request_url .= '?' . $data_string;
		}
		else
		{
            $data_string = json_encode($data);
            // header 头信息合并
            $post_header_arr = array('Content-Type: application/json', 'Content-Length: ' . strlen($data_string));
            $headers = array_merge($headers, $post_header_arr);
			curl_setopt($ch, CURLOPT_POST, 1);
			curl_setopt($ch, CURLOPT_POSTFIELDS, $data_string);
			curl_setopt($ch, CURLOPT_CUSTOMREQUEST, strtoupper($request_method));
        }
        curl_setopt($ch, CURLOPT_URL, $request_url);
        if($headers && is_array($headers))
        {
            curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        }
		// 执行 cURL 会话并获取返回响应结果
		$http_response = curl_exec($ch);
		$http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
		$http_errno = curl_errno($ch);
		$http_error = curl_error($ch);
		// 关闭请求句柄
        curl_close($ch);
        if($return_json)
        {
            try
            {
                $http_response = json_decode($http_response, true);
            }
            catch (Exception $e)
            {
                // 记录错误日志
            }
        }
        // 组装需要返回的数据结果
        $resp_data = array(
            // 增加1层结构进行包装，确保返回的数据结构的最外层是一致的
            'data' => array('resp_rs' => $http_response), 
            'http_code' => $http_code,
            'http_errno' => $http_errno,
            'http_error' => $http_error
        );
		return $resp_data;
	}

}
