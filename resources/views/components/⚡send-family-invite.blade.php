<?php

use App\Models\FamilyInvite;
use App\Models\User;
use App\Notifications\FamilyInviteNotification;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Livewire\Component;

new class extends Component
{
    public string $email = '';

    public function with(): array
    {
        $user = auth()->user();

        return [
            'isMember' => $user->family_owner_id !== null,
            'ownerName' => $user->family_owner_id !== null ? $user->familyOwner->name : null,
            'remainingSlots' => $user->remainingFamilySlots(),
        ];
    }

    public function save(): void
    {
        $owner = auth()->user();

        abort_if($owner->family_owner_id !== null, 403);

        $this->validate([
            'email' => ['required', 'email', 'exists:users,email'],
        ], [
            'email.exists' => 'Usuário não encontrado.',
        ]);

        $invitedUser = User::where('email', $this->email)->firstOrFail();

        if ($invitedUser->id === $owner->id) {
            $this->addError('email', 'Você não pode convidar a si mesmo.');

            return;
        }

        if ($invitedUser->family_owner_id !== null) {
            $this->addError('email', 'Esse usuário já faz parte de outra família.');

            return;
        }

        if ($invitedUser->familyMembers()->exists()) {
            $this->addError('email', 'Esse usuário já é dono de outra família com membros.');

            return;
        }

        if (FamilyInvite::where('invited_user_id', $invitedUser->id)->exists()) {
            $this->addError('email', 'Esse usuário já tem um convite pendente.');

            return;
        }

        if ($owner->remainingFamilySlots() < 1) {
            $this->addError('email', 'Você já atingiu o limite de 2 membros na família.');

            return;
        }

        $created = false;

        try {
            DB::transaction(function () use ($owner, $invitedUser, &$created) {
                $lockedOwner = User::whereKey($owner->id)->lockForUpdate()->first();

                if ($lockedOwner->remainingFamilySlots() < 1) {
                    return;
                }

                $invite = FamilyInvite::create([
                    'owner_id' => $owner->id,
                    'invited_user_id' => $invitedUser->id,
                ]);

                $invitedUser->notify(new FamilyInviteNotification($invite));

                $created = true;
            });
        } catch (QueryException $exception) {
            if ($exception->getCode() !== '23505') {
                throw $exception;
            }
        }

        if (! $created) {
            $this->addError('email', 'Não foi possível enviar o convite: limite de membros atingido ou usuário já convidado.');

            return;
        }

        $this->reset('email');
        $this->dispatch('family-invite-sent');
    }
};
?>

<div class="p-6 bg-white border border-gray-200 rounded-lg shadow-sm dark:bg-zinc-900 dark:border-zinc-700">
    <h3 class="text-lg font-medium text-gray-900 dark:text-gray-100 mb-4">Convidar para a Família</h3>

    @if ($isMember)
        <p class="text-sm text-gray-500 dark:text-gray-400">
            Você já faz parte da família de <strong>{{ $ownerName }}</strong>. Só o dono da família pode enviar convites.
        </p>
    @elseif ($remainingSlots < 1)
        <p class="text-sm text-gray-500 dark:text-gray-400">
            Você já atingiu o limite de 2 membros na sua família.
        </p>
    @else
        <form wire:submit="save" class="grid grid-cols-1 md:grid-cols-3 gap-4 md:items-end">
            <div class="md:col-span-2">
                <flux:input wire:model="email" label="E-mail do convidado" placeholder="usuario@exemplo.com" type="email" />
            </div>

            <flux:button type="submit" variant="primary">Convidar</flux:button>
        </form>
    @endif
</div>
