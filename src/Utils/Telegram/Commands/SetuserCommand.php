<?php

declare(strict_types=1);

namespace App\Utils\Telegram\Commands;

use App\Models\User;
use App\Utils\Telegram\Reply;
use App\Utils\Telegram\TelegramTools;
use Telegram\Bot\Actions;
use Telegram\Bot\Commands\Command;

/**
 * Class SetuserCommand.
 */
final class SetuserCommand extends Command
{
    /**
     * @var string Command Name
     */
    protected $name = 'setuser';

    /**
     * @var string Command Description
     */
    protected $description = '[群组/私聊] 修改用户数据，管理员命令.';

    /**
     * {@inheritdoc}
     */
    public function handle()
    {
        $Update = $this->getUpdate();
        $Message = $Update->getMessage();

        // 消息 ID
        $MessageID = $Message->getMessageId();

        // 消息会话 ID
        $ChatID = $Message->getChat()->getId();

        // 触发用户
        $SendUser = [
            'id' => $Message->getFrom()->getId(),
            'name' => $Message->getFrom()->getFirstName() . ' ' . $Message->getFrom()->getLastName(),
            'username' => $Message->getFrom()->getUsername(),
        ];

        if (! in_array($SendUser['id'], $_ENV['telegram_admins'])) {
            $AdminUser = User::where('is_admin', 1)->where('telegram_id', $SendUser['id'])->first();
            if ($AdminUser === null) {
                // 非管理员回复消息
                if ($_ENV['enable_not_admin_reply'] === true && $_ENV['not_admin_reply_msg'] !== '') {
                    $this->replyWithMessage(
                        [
                            'text' => $_ENV['not_admin_reply_msg'],
                            'parse_mode' => 'HTML',
                            'reply_to_message_id' => $MessageID,
                        ]
                    );
                }
                return;
            }
        }

        // 发送 '输入中' 会话状态
        $this->replyWithChatAction(['action' => Actions::TYPING]);

        return $this->reply($SendUser, $Message, $MessageID, $ChatID);
    }

    public function reply($SendUser, $Message, $MessageID, $ChatID): void
    {
        $User = null;
        $FindUser = null;
        $argumentsExplode = explode(' ', trim($Message->getText()));
        $arguments = implode(' ', $argumentsExplode);
        if ($Message->getReplyToMessage() !== null) {
            // 回复源消息用户
            $FindUser = [
                'id' => $Message->getReplyToMessage()->getFrom()->getId(),
                'name' => $Message->getReplyToMessage()->getFrom()->getFirstName() . ' ' . $Message->getReplyToMessage()->getFrom()->getLastName(),
                'username' => $Message->getReplyToMessage()->getFrom()->getUsername(),
            ];
            $User = TelegramTools::getUser($FindUser['id']);
            if ($User === null) {
                $this->replyWithMessage(
                    [
                        'text' => $_ENV['no_user_found'],
                        'parse_mode' => 'HTML',
                        'reply_to_message_id' => $MessageID,
                    ]
                );
                return;
            }

            if ($arguments === '/setuser') {
                // 无参数时回复用户信息
                $this->replyWithMessage(
                    [
                        'text' => Reply::getUserInfoFromAdmin($User, $ChatID),
                        'reply_to_message_id' => $MessageID,
                    ]
                );
                return;
            }
        }

        if ($arguments === '/setuser') {
            $strArray = [
                '/setuser [用户识别] [操作字段] [操作参数]',
                '',
                '用户识别：[使用回复消息方式则无需填写]',
                '- [' . implode(' | ', array_keys(TelegramTools::getUserSearchMethods())) . ']，可省略，默认：email',
                '- 例 1：/setuser admin@admin 操作字段 操作参数',
                '- 例 2：/setuser port:10086  操作字段 操作参数',
                '',
                '操作字段：',
                '[' . implode(' | ', array_keys(TelegramTools::getUserActionOption())) . ']',
                '',
                '操作参数：',
                '- 请查看对应选项支持的写法.',
            ];
            $this->replyWithMessage(
                [
                    'text' => TelegramTools::strArrayToCode($strArray),
                    'parse_mode' => 'HTML',
                    'reply_to_message_id' => $MessageID,
                ]
            );
            return;
        }

        // 命令格式：
        // - /setuser [用户识别码] 选项 操作值

        // ############## 命令解析 ##############
        $UserCode = '';
        if ($User === null) {
            $Options = TelegramTools::strExplode($arguments, ' ', 3);
            if (count($Options) < 3) {
                $this->replyWithMessage(
                    [
                        'text' => '没有提供选项或操作值.',
                        'parse_mode' => 'HTML',
                        'reply_to_message_id' => $MessageID,
                    ]
                );
                return;
            }
            // 用户识别码
            $UserCode = $Options[0];
            // 选项
            $Option = $Options[1];
            // 操作值
            $value = $Options[2];
        } else {
            $Options = TelegramTools::strExplode($arguments, ' ', 2);
            if (count($Options) < 2) {
                $this->replyWithMessage(
                    [
                        'text' => '没有提供选项或操作值.',
                        'parse_mode' => 'HTML',
                        'reply_to_message_id' => $MessageID,
                    ]
                );
                return;
            }
            // 选项
            $Option = $Options[0];
            // 操作值
            $value = $Options[1];
        }
        // ############## 命令解析 ##############

        // ############## 用户识别码处理 ##############
        if ($User === null) {
            // 默认搜寻字段
            $useMethod = 'email';
            if (strpos($UserCode, ':') !== false) {
                // 如果指定了字段
                $UserCodeExplode = explode(':', $UserCode);
                $Search = $UserCodeExplode[0];
                $UserCode = $UserCodeExplode[1];
                $SearchMethods = TelegramTools::getUserSearchMethods();
                $useTempMethod = TelegramTools::getOptionMethod($SearchMethods, $Search);
                if ($useTempMethod !== '') {
                    $useMethod = $useTempMethod;
                }
            }
            $User = TelegramTools::getUser($UserCode, $useMethod);
            if ($User === null) {
                $this->replyWithMessage(
                    [
                        'text' => $_ENV['no_user_found'],
                        'parse_mode' => 'HTML',
                        'reply_to_message_id' => $MessageID,
                    ]
                );
                return;
            }
        }
        // ############## 用户识别码处理 ##############

        // ############## 字段选项处理 ##############
        $OptionMethods = TelegramTools::getUserActionOption();
        $useOptionMethod = TelegramTools::getOptionMethod($OptionMethods, $Option);
        if ($useOptionMethod === '') {
            $this->replyWithMessage(
                [
                    'text' => $_ENV['data_method_not_found'],
                    'parse_mode' => 'HTML',
                    'reply_to_message_id' => $MessageID,
                ]
            );
            return;
        }
        // ############## 字段选项处理 ##############

        $reply = TelegramTools::operationUser($User, $useOptionMethod, $value, $ChatID);
        $this->replyWithMessage(
            [
                'text' => $reply['msg'],
                'parse_mode' => 'HTML',
                'reply_to_message_id' => $MessageID,
            ]
        );
        return;
    }
}
