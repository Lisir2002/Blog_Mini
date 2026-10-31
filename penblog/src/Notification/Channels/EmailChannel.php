<?php

declare(strict_types=1);

namespace PenBlog\Notification\Channels;

use PenBlog\Auth\User;
use PenBlog\Notification\ChannelAdapter;
use PenBlog\Notification\DeliveryResult;
use PenBlog\Notification\Mailer;

final class EmailChannel implements ChannelAdapter
{
    public function __construct(private readonly Mailer $mailer)
    {
    }

    public function name(): string
    {
        return 'email';
    }

    public function send(array $rendered, User $to): DeliveryResult
    {
        $email = $to->email();
        if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return DeliveryResult::failure('missing_email', false);
        }
        $ok = $this->mailer->send($email, $rendered['title'], $rendered['body']);
        return $ok ? DeliveryResult::success() : DeliveryResult::failure('mail_send_failed');
    }
}
