<?php
namespace Core\Http;

use \App;
use Core\Component;
use Core\Event\Event;
use Core\Lib\Cipher;

class Cookies extends Component implements \IteratorAggregate, \Countable
{
    const EVENT_SET_COOKIE = 'set_cookie';

    const EVENT_SET_SECURE_COOKIE = 'set_secure_cookie';

    protected $data = [];

    protected $cipher;

    protected $defaults = [
        'value' => '',    // cookie值
        'expire' => 0,     // 过期时间戳
        'domain' => null,  // 作用域名
        'path' => '/',  // cookie目录
        'secure' => false, // 是否使用https安全连接
        'httponly' => true  // 设为true可防止cookies被js读取，提高安全性
    ];

    public function __construct(array $cookies = null)
    {
        if (null === $cookies) {
            $cookies = $_COOKIE;
        }
        if (!empty($cookies)) {
            foreach ($cookies as $key => $value) {
                if (is_string($value)) $value = ['value' => $value];
                $this->data[$key] = array_merge($this->defaults, $value);
            }
        }
    }

    /**
     * 改变默认设置
     *
     * 应该在调用set方法之前设置
     *
     * @param array $defaults
     */
    public function setDefaults(array $defaults)
    {
        $this->defaults = array_merge($this->defaults, $defaults);
    }

    /**
     * 设置cookie
     *
     * 当$value为字符串时，表示把 $value 当作cookie值，其他选项使用默认设置。
     * 当$value为数组时，表示自定义参数，必须数组必须包含一个名为 value 的键表示cookie值。
     *
     * @param string $name
     * @param string|array $value
     */
    public function set($name, $value)
    {
        if (is_string($value)) $value = ['value' => $value];
        $this->data[$name] = array_merge($this->defaults, $value);
        $this->trigger(self::EVENT_SET_COOKIE);
    }

    /**
     * 获取cookie
     *
     * @param string $name 名称
     * @return null|string 返回cookie值，不存在时返回null
     */
    public function get($name)
    {
        if (isset($this->data[$name])) {
            return $this->data[$name]['value'];
        }
        return null;
    }

    /**
     * 设置加密器
     *
     * @param object $cipher
     * @return bool
     */
    public function setCipher($cipher)
    {
        if (!is_object($cipher)) {
            return false;
        }
        $this->cipher = $cipher;
        return true;
    }

    /**
     * 获取加密器
     *
     * @return Cipher
     */
    public function getCipher()
    {
        if (!$this->cipher) {
            $this->cipher = Cipher::createSimple();
        }
        return $this->cipher;
    }

    /**
     * 设置加密的cookie
     *
     * @param string $name
     * @param string $value
     * @param string $secret
     */
    public function setSecure($name, $value, $secret = null)
    {
        if ($secret === null) {
            $secret = App::config()->get('app', 'secret_key');
        }
        if (empty($secret)) {
            throw new \RuntimeException("请先到app配置文件设置密钥: secret_key");
        }
        if (is_string($value)) $value = ['value' => $value];
        $value['value'] = $this->getCipher()->encrypt($value['value'], $secret);
        $this->set($name, $value);
        $this->trigger(self::EVENT_SET_SECURE_COOKIE);
    }

    /**
     * 获取并解密cookie
     *
     * @param string $name
     * @param string $secret
     * @return null|string
     */
    public function getSecure($name, $secret = null)
    {
        if ($secret === null) {
            $secret = App::config()->get('app', 'secret_key');
        }
        if (empty($secret)) {
            throw new \RuntimeException("请先到app配置文件设置密钥: secret_key");
        }
        $value = $this->get($name);
        if ($value) {
            $value = $this->getCipher()->decrypt($value, $secret);
        }
        return $value;
    }

    /**
     * 移除cookie
     *
     * 该方法不是用于删除已设置的cookie，而是向浏览器发出cookie过期的头信息，以清除浏览器cookie
     *
     * @param $name
     */
    public function remove($name)
    {
        $this->set($name, ['expire' => time() - 86400]);
    }

    /**
     * 检查cookie是否存在
     *
     * @param string $name
     * @return bool
     */
    public function has($name)
    {
        return isset($this->data[$name]);
    }

    /**
     * 获取迭代器
     *
     * @return \ArrayIterator|\Traversable
     */
    public function getIterator()
    {
        return new \ArrayIterator($this->data);
    }

    public function count()
    {
        return count($this->data);
    }

    public function parseValue($name, array $value)
    {
        $values = [];
        if (isset($value['expire'])) {
            if (is_string($value['expire'])) {
                $timestamp = strtotime($value['expire']);
            } else {
                $timestamp = (int)$value['expire'];
            }
            if ($timestamp !== 0) {
                $values[] = '; expires=' . gmdate('D, d-M-Y H:i:s e', $timestamp);
            }
        }
        if (isset($value['path']) && $value['path']) {
            $values[] = '; path=' . $value['path'];
        }
        if (isset($value['domain']) && $value['domain']) {
            $values[] = '; domain=' . $value['domain'];
        }
        if (isset($value['secure']) && $value['secure']) {
            $values[] = '; secure';
        }
        if (isset($value['httponly']) && $value['httponly']) {
            $values[] = '; HttpOnly';
        }
        return sprintf("%s=%s", urlencode($name), urlencode($value['value']) . implode('', $values));
    }

    public function __toString()
    {
        $cookies = [];
        foreach ($this->data as $name => $value) {
            $cookies[] = sprintf("Set-Cookie: %s", $this->parseValue($name, $value));
        }
        return implode("\r\n", $cookies);
    }
}
