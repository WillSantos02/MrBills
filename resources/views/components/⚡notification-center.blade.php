<?php

use App\Enums\BillStatus;
use App\Models\Bill;
use Livewire\Component;

new class extends Component
{
    public function with(): array
    {
        $notifications = auth()->user()->unreadNotifications()->latest()->get();

        return [
            'notifications' => $notifications,
            'unreadCount' => $notifications->count(),
        ];
    }

    public function markBillAsPaid(string $notificationId): void
    {
        $notification = auth()->user()->notifications()->findOrFail($notificationId);

        Bill::where('user_id', auth()->id())
            ->where('id', $notification->data['bill_id'])
            ->update(['status' => BillStatus::Pago]);

        $notification->markAsRead();
    }

    public function remindLater(string $notificationId): void
    {
        auth()->user()->notifications()->findOrFail($notificationId)->markAsRead();
    }
};
?>

<div>
    <flux:dropdown position="bottom" align="end">
        <span class="relative inline-block">
            <flux:tooltip :content="__('Notificações')" position="bottom">
                <flux:button variant="ghost" size="sm" icon="bell" data-test="notification-bell" />
            </flux:tooltip>

            @if ($unreadCount > 0)
                <flux:badge color="red" size="sm" class="absolute -top-1 -right-1 pointer-events-none">
                    {{ $unreadCount }}
                </flux:badge>
            @endif
        </span>

        <flux:menu class="w-80">
            <div class="px-3 py-2">
                <flux:heading size="sm">{{ __('Notificações') }}</flux:heading>
            </div>

            <flux:menu.separator />

            @forelse ($notifications as $notification)
                <div class="px-3 py-2 border-b border-zinc-100 last:border-b-0 dark:border-zinc-700">
                    <p class="text-sm text-zinc-700 mb-2 dark:text-zinc-300">
                        {{ $notification->data['message'] }}
                    </p>

                    <div class="flex gap-2">
                        <flux:button size="sm" variant="primary" wire:click="markBillAsPaid('{{ $notification->id }}')">
                            {{ __('Marcar como pago') }}
                        </flux:button>
                        <flux:button size="sm" variant="ghost" wire:click="remindLater('{{ $notification->id }}')">
                            {{ __('Lembrar depois') }}
                        </flux:button>
                    </div>
                </div>
            @empty
                <div class="px-3 py-4 text-center text-sm text-zinc-500 dark:text-zinc-400">
                    {{ __('Nenhuma notificação pendente.') }}
                </div>
            @endforelse
        </flux:menu>
    </flux:dropdown>
</div>
