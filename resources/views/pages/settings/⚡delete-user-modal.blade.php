<?php

use App\Concerns\PasswordValidationRules;
use App\Livewire\Actions\Logout;
use App\Models\OwnershipTransferRequest;
use App\Models\User;
use App\Notifications\OwnershipTransferRequestNotification;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Livewire\Component;

new class extends Component {
    use PasswordValidationRules;

    public string $password = '';

    public string $selectedMemberId = '';

    public function with(): array
    {
        $user = auth()->user();
        $trashedFamilyOwner = $user->trashedFamilyOwner();

        return [
            'familyMembersForTransfer' => $user->familyMembers()->get(['id', 'name']),
            'pendingTransfer' => $user->sentOwnershipTransferRequest,
            'transferDeclined' => $user->family_transfer_declined_at !== null,
            'willDissolveFamily' => $trashedFamilyOwner !== null && $user->isLastActiveFamilyMember(),
            'trashedFamilyOwnerName' => $trashedFamilyOwner?->name,
        ];
    }

    /**
     * Envia (ou reenvia, pra outro membro) um pedido de transferência de titularidade da família.
     */
    public function requestOwnershipTransfer(): void
    {
        $this->validate([
            'selectedMemberId' => [
                'required',
                Rule::exists('users', 'id')->where('family_owner_id', auth()->id()),
            ],
        ]);

        $toUser = User::findOrFail($this->selectedMemberId);

        DB::transaction(function () use ($toUser) {
            OwnershipTransferRequest::where('from_user_id', auth()->id())->delete();

            $transferRequest = OwnershipTransferRequest::create([
                'from_user_id' => auth()->id(),
                'to_user_id' => $toUser->id,
            ]);

            $toUser->notify(new OwnershipTransferRequestNotification($transferRequest));
        });

        $this->reset('selectedMemberId');
    }

    public function cancelOwnershipTransferRequest(): void
    {
        OwnershipTransferRequest::where('from_user_id', auth()->id())->delete();
    }

    /**
     * Exclusão definitiva — só permitida quando o usuário não é mais dono de uma família com membros
     * (nunca foi, ou já transferiu a titularidade). Se for o último integrante ativo de uma família cujo
     * antigo dono ficou soft-deleted (recusou transferir e depois excluiu a conta), a dissolução da
     * família também apaga definitivamente os dados preservados desse antigo dono.
     */
    public function deleteUser(Logout $logout): void
    {
        $this->validate([
            'password' => $this->currentPasswordRules(),
        ]);

        $user = Auth::user();

        if ($user->familyMembers()->count() > 0) {
            $this->addError('password', __('Transfira a titularidade da família antes de excluir sua conta.'));

            return;
        }

        $trashedFamilyOwner = $user->trashedFamilyOwner();
        $shouldPurgeTrashedOwner = $trashedFamilyOwner !== null && $user->isLastActiveFamilyMember();

        tap($user, $logout(...))->forceDelete();

        if ($shouldPurgeTrashedOwner) {
            $trashedFamilyOwner->forceDelete();
        }

        $this->redirect('/', navigate: true);
    }

    /**
     * Exclusão preservando os dados (soft-delete) — só depois de uma transferência de titularidade
     * recusada. Os dados continuam visíveis pra família (via `familyGroupUserIds()`) até ela dissolver.
     */
    public function softDeleteUser(Logout $logout): void
    {
        $this->validate([
            'password' => $this->currentPasswordRules(),
        ]);

        $user = Auth::user();

        if ($user->family_transfer_declined_at === null) {
            $this->addError('password', __('Você ainda não tentou transferir a titularidade.'));

            return;
        }

        tap($user, $logout(...))->delete();

        $this->redirect('/', navigate: true);
    }
}; ?>

<flux:modal name="confirm-user-deletion" :show="$errors->isNotEmpty()" focusable class="max-w-lg">
    @if ($familyMembersForTransfer->isEmpty())
        {{-- Não é dono de família com membros: exclusão normal (definitiva). --}}
        <form method="POST" wire:submit="deleteUser" class="space-y-6">
            <div>
                <flux:heading size="lg">{{ __('Are you sure you want to delete your account?') }}</flux:heading>

                <flux:subheading>
                    {{ __('Once your account is deleted, all of its resources and data will be permanently deleted. Please enter your password to confirm you would like to permanently delete your account.') }}
                </flux:subheading>

                @if ($willDissolveFamily)
                    <flux:callout variant="danger" class="mt-4">
                        {{ __('Você é o último integrante ativo da família. Ao excluir sua conta, o histórico de :name também será apagado definitivamente.', ['name' => $trashedFamilyOwnerName]) }}
                    </flux:callout>
                @endif
            </div>

            <flux:input wire:model="password" :label="__('Password')" type="password" viewable />

            <div class="flex justify-end space-x-2 rtl:space-x-reverse">
                <flux:modal.close>
                    <flux:button variant="filled">{{ __('Cancel') }}</flux:button>
                </flux:modal.close>

                <flux:button variant="danger" type="submit" data-test="confirm-delete-user-button">
                    {{ __('Delete account') }}
                </flux:button>
            </div>
        </form>
    @elseif ($pendingTransfer)
        {{-- Pedido de transferência enviado, aguardando resposta do membro escolhido. --}}
        <div class="space-y-6">
            <div>
                <flux:heading size="lg">{{ __('Transferência de titularidade pendente') }}</flux:heading>
                <flux:subheading>
                    {{ __('Aguardando aceite de :name. Você poderá excluir sua conta assim que a transferência for aceita.', ['name' => $pendingTransfer->toUser->name]) }}
                </flux:subheading>
            </div>

            <div class="flex justify-end space-x-2 rtl:space-x-reverse">
                <flux:modal.close>
                    <flux:button variant="filled">{{ __('Fechar') }}</flux:button>
                </flux:modal.close>

                <flux:button variant="danger" wire:click="cancelOwnershipTransferRequest">
                    {{ __('Cancelar pedido') }}
                </flux:button>
            </div>
        </div>
    @else
        {{-- Dono de família com membros: precisa transferir a titularidade antes de excluir a conta. --}}
        <div class="space-y-6">
            <div>
                <flux:heading size="lg">{{ __('Transferir titularidade da família') }}</flux:heading>
                <flux:subheading>
                    {{ __('Você é o(a) titular de uma família com integrantes ativos. Escolha quem deve se tornar o(a) novo(a) titular dos dados antes de excluir sua conta.') }}
                </flux:subheading>

                @if ($transferDeclined)
                    <flux:callout variant="warning" class="mt-4">
                        {{ __('O último pedido de transferência foi recusado. Você pode tentar com outro integrante, ou excluir sua conta preservando os dados enquanto a família existir.') }}
                    </flux:callout>
                @endif
            </div>

            <div class="flex gap-2 items-end">
                <flux:select wire:model="selectedMemberId" :label="__('Novo(a) titular')" class="flex-1">
                    <flux:select.option value="">{{ __('Selecione um integrante') }}</flux:select.option>
                    @foreach ($familyMembersForTransfer as $member)
                        <flux:select.option value="{{ $member->id }}">{{ $member->name }}</flux:select.option>
                    @endforeach
                </flux:select>

                <flux:button variant="primary" wire:click="requestOwnershipTransfer">
                    {{ __('Enviar pedido') }}
                </flux:button>
            </div>

            @if ($transferDeclined)
                <form method="POST" wire:submit="softDeleteUser" class="space-y-4 pt-4 border-t border-zinc-200 dark:border-zinc-700">
                    <flux:subheading>
                        {{ __('Ao excluir mesmo assim, sua conta para de funcionar mas os dados que você registrou continuam visíveis e editáveis pela família até ela deixar de existir.') }}
                    </flux:subheading>

                    <flux:input wire:model="password" :label="__('Password')" type="password" viewable />

                    <div class="flex justify-end space-x-2 rtl:space-x-reverse">
                        <flux:modal.close>
                            <flux:button variant="filled">{{ __('Cancel') }}</flux:button>
                        </flux:modal.close>

                        <flux:button variant="danger" type="submit" data-test="confirm-soft-delete-user-button">
                            {{ __('Excluir mesmo assim') }}
                        </flux:button>
                    </div>
                </form>
            @else
                <div class="flex justify-end">
                    <flux:modal.close>
                        <flux:button variant="filled">{{ __('Fechar') }}</flux:button>
                    </flux:modal.close>
                </div>
            @endif
        </div>
    @endif
</flux:modal>
