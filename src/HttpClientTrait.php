<?php declare(strict_types=1);
/**
 * DuckPhp
 * From this time, you never be alone~
 */

namespace DuckCoverage;

trait HttpClientTrait
{
    protected $cookies = [];
    protected $post = [];

    protected function cleanClientStatus()
    {
        $this->cookies = [];
    }

    protected $current_url_prefix = '';

    protected $pre_curl;
    protected $post_curl;
    protected $pre_webcall;
    protected $post_webcall;

    public function prepareCurl($ch)
    {
        $this->headers[] = 'X-MyCoverage-Name: ' . $this->watchingGetName();
        if ($this->pre_webcall) {
            $this->headers[] = 'X-MyCoverage-BeforeRun: ' . $this->pre_webcall;
            $this->pre_webcall = null;
        }
        if ($this->post_webcall) {
            $this->headers[] = 'X-MyCoverage-AfterRun: ' . $this->post_webcall;
            $this->post_webcall = null;
        }
        $pre_curl = $this->pre_curl;
        $this->pre_curl = null;

        //////////////////////////
        if (!$pre_curl || $pre_curl === '_') {
            return $ch;
        }

        if ($pre_curl === 'AJAX') {
            $this->headers[] = 'X-Requested-With: XMLHttpRequest';
            return $ch;
        }
        if ($pre_curl === 'OPTIONS') {
            curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'OPTIONS');
            return $ch;
        }
        $this->callHandler($pre_curl, [$ch, 'pre']);
        return $ch;
    }

    public function postpareCurl($ch)
    {
        $post_curl = $this->post_curl;
        $this->post_curl = null;

        $this->callHandler($post_curl, [$ch, 'post']);
    }

    protected $headers = [];

    protected function curl_file_get_contents($url, $post = [], $is_ajax = false, $is_options = false, $method = '')
    {
        $ch = curl_init();

        if (is_array($url)) {
            list($base_url, $real_host) = $url;
            $url = $base_url;
            $host = parse_url($url, PHP_URL_HOST);
            $port = parse_url($url, PHP_URL_PORT);
            $c = $host . ':' . $port . ':' . $real_host;
            curl_setopt($ch, CURLOPT_CONNECT_TO, [$c]);
        }
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
        //curl_setopt($ch, CURLOPT_FOLLOWLOCATION, 1); 

        // 始终抓取响应头，以收集/更新所有 Set-Cookie
        curl_setopt($ch, CURLOPT_HEADER, 1);

        if (!empty($post)) {
            curl_setopt($ch, CURLOPT_POST, 1);
            curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($post));
        }
        /////////
        // 连续传递所有已收集的 cookie（不再只传 PHPSESSID）
        if (!empty($this->cookies)) {
            $cookie_str = [];
            foreach ($this->cookies as $name => $value) {
                $cookie_str[] = $name . '=' . $value;
            }
            curl_setopt($ch, CURLOPT_COOKIE, implode('; ', $cookie_str));
        }

        $this->prepareCurl($ch);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $this->headers);
        $data = curl_exec($ch);
        $this->headers = [];
        // 收集响应中的所有 Set-Cookie，同名覆盖（空值/deleted 移除）
        $header_size = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
        $headers = substr($data, 0, $header_size);
        $data = substr($data, $header_size);
        if (preg_match_all('/Set-Cookie:\s*([^=;\s]+)=([^;]*)/i', $headers, $ms)) {
            foreach ($ms[1] as $i => $name) {
                $value = trim($ms[2][$i]);
                if ($value === '' || strcasecmp($value, 'deleted') === 0) {
                    unset($this->cookies[$name]);
                } else {
                    $this->cookies[$name] = $value;
                }
            }
        }
        $this->postpareCurl($ch);
        echo $url;
        echo ' ';
        echo http_build_query($post);
        echo "\n";
        //echo $data;
        curl_close($ch);
        $data = ($data !== false) ? $data : '';
        return $data;
    }
}
