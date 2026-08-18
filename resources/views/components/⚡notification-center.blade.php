<?php

use App\Enums\BillStatus;
use App\Models\Bill;
use App\Models\Category;
use App\Models\FamilyInvite;
use App\Models\Income;
use App\Models\IncomeCategory;
use App\Models\OwnershipTransferRequest;
use App\Models\User;
use App\Notifications\FamilyInviteAcceptedNotification;
use App\Notifications\OwnershipTransferAcceptedNotification;
use App\Notifications\OwnershipTransferRejectedNotification;
use Illuminate\Support\Facades\DB;
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

    public function acceptInvite(string $notificationId): void
    {
        $notification = auth()->user()->notifications()->findOrFail($notificationId);

        $invite = FamilyInvite::where('invited_user_id', auth()->id())
            ->find($notification->data['family_invite_id']);

        if ($invite === null) {
            $notification->delete();

            return;
        }

        DB::transaction(function () use ($invite) {
            $owner = User::whereKey($invite->owner_id)->lockForUpdate()->first();

            if ($owner->familyMembers()->count() >= 2) {
                $invite->delete();

                return;
            }

            auth()->user()->update(['family_owner_id' => $owner->id]);
            FamilyInvite::where('owner_id', auth()->id())->delete();
            $invite->delete();

            $owner->notify(new FamilyInviteAcceptedNotification(auth()->user()));
        });

        $notification->delete();
    }

    public function rejectInvite(string $notificationId): void
    {
        $notification = auth()->user()->notifications()->findOrFail($notificationId);

        FamilyInvite::where('invited_user_id', auth()->id())
            ->where('id', $notification->data['family_invite_id'])
            ->delete();

        $notification->delete();
    }

    public function dismiss(string $notificationId): void
    {
        auth()->user()->notifications()->findOrFail($notificationId)->delete();
    }

    public function acceptOwnershipTransfer(string $notificationId): void
    {
        $notification = auth()->user()->notifications()->findOrFail($notificationId);

        $transferRequest = OwnershipTransferRequest::where('to_user_id', auth()->id())
            ->find($notification->data['transfer_request_id']);

        if ($transferRequest === null) {
            $notification->delete();

            return;
        }

        DB::transaction(function () use ($transferRequest) {
            $fromUser = User::whereKey($transferRequest->from_user_id)->lockForUpdate()->first();
            $toUser = auth()->user();

            // Categorias com o mesmo nome já existentes no novo titular são mescladas (contas/receitas
            // repontadas pra categoria já existente) em vez de reatribuídas, senão o update em massa a
            // seguir colidiria com o unique(user_id, name) das duas tabelas.
            Category::where('user_id', $fromUser->id)->get()->each(function (Category $category) use ($toUser) {
                $existing = Category::where('user_id', $toUser->id)->where('name', $category->name)->first();

                if ($existing !== null) {
                    $category->bills()->update(['category_id' => $existing->id]);
                    $category->delete();
                } else {
                    $category->update(['user_id' => $toUser->id]);
                }
            });

            IncomeCategory::where('user_id', $fromUser->id)->get()->each(function (IncomeCategory $category) use ($toUser) {
                $existing = IncomeCategory::where('user_id', $toUser->id)->where('name', $category->name)->first();

                if ($existing !== null) {
                    $category->incomes()->update(['income_category_id' => $existing->id]);
                    $category->delete();
                } else {
                    $category->update(['user_id' => $toUser->id]);
                }
            });

            Bill::where('user_id', $fromUser->id)->update(['user_id' => $toUser->id]);
            Income::where('user_id', $fromUser->id)->update(['user_id' => $toUser->id]);

            $toUser->update(['family_owner_id' => null]);
            User::where('family_owner_id', $fromUser->id)
                ->where('id', '!=', $toUser->id)
                ->update(['family_owner_id' => $toUser->id]);
            $fromUser->update(['family_owner_id' => $toUser->id, 'family_transfer_declined_at' => null]);

            $transferRequest->delete();

            $fromUser->notify(new OwnershipTransferAcceptedNotification($toUser));
        });

        $notification->delete();
    }

    public function rejectOwnershipTransfer(string $notificationId): void
    {
        $notification = auth()->user()->notifications()->findOrFail($notificationId);

        $transferRequest = OwnershipTransferRequest::where('to_user_id', auth()->id())
            ->find($notification->data['transfer_request_id']);

        if ($transferRequest !== null) {
            $fromUser = $transferRequest->fromUser;
            $transferRequest->delete();
            $fromUser->update(['family_transfer_declined_at' => now()]);
            $fromUser->notify(new OwnershipTransferRejectedNotification(auth()->user()));
        }

        $notification->delete();
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
                        @if ($notification->type === \App\Notifications\BillDueSoonNotification::class)
                            <flux:button size="sm" variant="primary" wire:click="markBillAsPaid('{{ $notification->id }}')">
                                {{ __('Marcar como pago') }}
                            </flux:button>
                            <flux:button size="sm" variant="ghost" wire:click="remindLater('{{ $notification->id }}')">
                                {{ __('Lembrar depois') }}
                            </flux:button>
                        @elseif ($notification->type === \App\Notifications\FamilyInviteNotification::class)
                            <flux:button size="sm" variant="primary" wire:click="acceptInvite('{{ $notification->id }}')">
                                {{ __('Aceitar') }}
                            </flux:button>
                            <flux:button size="sm" variant="danger" wire:click="rejectInvite('{{ $notification->id }}')">
                                {{ __('Recusar') }}
                            </flux:button>
                        @elseif ($notification->type === \App\Notifications\FamilyInviteAcceptedNotification::class)
                            <flux:button size="sm" variant="ghost" wire:click="dismiss('{{ $notification->id }}')">
                                {{ __('Ok') }}
                            </flux:button>
                        @elseif ($notification->type === \App\Notifications\OwnershipTransferRequestNotification::class)
                            <flux:button size="sm" variant="primary" wire:click="acceptOwnershipTransfer('{{ $notification->id }}')">
                                {{ __('Aceitar') }}
                            </flux:button>
                            <flux:button size="sm" variant="danger" wire:click="rejectOwnershipTransfer('{{ $notification->id }}')">
                                {{ __('Recusar') }}
                            </flux:button>
                        @elseif ($notification->type === \App\Notifications\OwnershipTransferAcceptedNotification::class || $notification->type === \App\Notifications\OwnershipTransferRejectedNotification::class)
                            <flux:button size="sm" variant="ghost" wire:click="dismiss('{{ $notification->id }}')">
                                {{ __('Ok') }}
                            </flux:button>
                        @endif
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
