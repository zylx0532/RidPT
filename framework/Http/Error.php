<?php

namespace Rid\Http;

use Rid\Base\Component;

/**
 * Error类
 */
class Error extends Component
{

    // 格式值
    const FORMAT_HTML = 'html';
    const FORMAT_JSON = 'json';

    // 输出格式
    public $format = self::FORMAT_HTML;

    // 初始化事件
    public function onInitialize()
    {
        parent::onInitialize(); // TODO: Change the autogenerated stub
        // 设置协程模式
        $this->setCoroutineMode(Component::COROUTINE_MODE_REFERENCE);
    }

    // 异常处理
    public function handleException($e)
    {
        // debug处理 & exit处理
        if ($e instanceof \Rid\Exceptions\DebugException || $e instanceof \Rid\Exceptions\EndException) {
            \Rid::app()->response->content = $e->getMessage();
            \Rid::app()->response->send();
            return;
        }
        // 错误参数定义
        $statusCode = $e instanceof \Rid\Exceptions\NotFoundException ? 404 : 500;
        $errors     = [
            'status'  => $statusCode,
            'code'    => $e->getCode(),
            'message' => $e->getMessage(),
            'file'    => $e->getFile(),
            'line'    => $e->getLine(),
            'type'    => get_class($e),
            'trace'   => $e->getTraceAsString(),
        ];
        // 日志处理
        if (!($e instanceof \Rid\Exceptions\NotFoundException)) {
            $message = "{$errors['message']}" . PHP_EOL;
            $message .= "[type] {$errors['type']} [code] {$errors['code']}" . PHP_EOL;
            $message .= "[file] {$errors['file']} [line] {$errors['line']}" . PHP_EOL;
            $message .= "[trace] {$errors['trace']}" . PHP_EOL;
            $message .= '$_SERVER' . substr(print_r(\Rid::app()->request->server() + \Rid::app()->request->header(), true), 5);
            $message .= '$_GET' . substr(print_r(\Rid::app()->request->get(), true), 5);
            $message .= '$_POST' . substr(print_r(\Rid::app()->request->post(), true), 5, -1);
            \Rid::app()->log->error($message);
        }
        // 清空系统错误
        ob_get_contents() and ob_clean();

        \Rid::app()->response->statusCode = $statusCode;
        \Rid::app()->response->content    = (new View())->render('error', $errors);
        \Rid::app()->response->format     = Response::FORMAT_HTML;

        \Rid::app()->response->send();
    }

}
