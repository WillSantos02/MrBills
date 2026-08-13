<?php

use App\Models\FamilyInvite;
use Livewire\Attributes\On;
use Livewire\Component;

new class extends Component
{
    #[On('family-invite-sent')]
    public function refresh(): void
    {
        // Livewire re-renderiza automaticamente o componente ao disparar o listener.
    }

    public function with(): array
    {
        $user = auth()->user();

        if ($user->family_owner_id !== null) {
            return [
                'role' => 'member',
                'owner' => $user->familyOwner,
                'siblings' => $user->familyOwner->familyMembers()->where('id', '!=', $user->id)->get(),
            ];
        }

        return [
            'role' => 'owner',
            'members' => $user->familyMembers()->get(),
            'pendingInvites' => $user->sentFamilyInvites()->with('invitedUser')->get(),
        ];
    }

    public function cancelInvite(int $inviteId): void
    {
        FamilyInvite::where('owner_id', auth()->id())
            ->where('id', $inviteId)
            ->delete();
    }
};
?>

<div class="p-6 bg-white border border-gray-200 rounded-lg shadow-sm dark:bg-zinc-900 dark:border-zinc-700">
    <h3 class="text-lg font-medium text-gray-900 dark:text-gray-100 mb-4">Minha Família</h3>

    @if ($role === 'member')
        <p class="text-sm text-gray-500 dark:text-gray-400 mb-4">
            Você faz parte da família de <strong>{{ $owner->name }}</strong> ({{ $owner->email }}).
        </p>

        @if ($siblings->isNotEmpty())
            <ul class="text-sm text-gray-700 dark:text-gray-300 space-y-1">
                @foreach ($siblings as $sibling)
                    <li>{{ $sibling->name }} ({{ $sibling->email }})</li>
                @endforeach
            </ul>
        @endif
    @else
        <div class="mb-6">
            <h4 class="text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">Membros</h4>

            @if ($members->isEmpty())
                <p class="text-sm text-gray-500 dark:text-gray-400">Nenhum membro por enquanto.</p>
            @else
                <ul class="text-sm text-gray-700 dark:text-gray-300 space-y-1">
                    @foreach ($members as $member)
                        <li>{{ $member->name }} ({{ $member->email }})</li>
                    @endforeach
                </ul>
            @endif
        </div>

        <div>
            <h4 class="text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">Convites Pendentes</h4>

            @if ($pendingInvites->isEmpty())
                <p class="text-sm text-gray-500 dark:text-gray-400">Nenhum convite pendente.</p>
            @else
                <ul class="space-y-2">
                    @foreach ($pendingInvites as $invite)
                        <li class="flex items-center justify-between text-sm text-gray-700 dark:text-gray-300">
                            <span>{{ $invite->invitedUser->name }} ({{ $invite->invitedUser->email }})</span>
                            <button type="button" wire:click="cancelInvite({{ $invite->id }})" class="text-red-600 hover:underline dark:text-red-400">
                                Cancelar convite
                            </button>
                        </li>
                    @endforeach
                </ul>
            @endif
        </div>
    @endif
</div>
