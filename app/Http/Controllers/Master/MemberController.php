<?php

namespace App\Http\Controllers\Master;

use App\Http\Controllers\Controller;
use App\Models\Member;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Redirect;
use Inertia\Inertia;
use Inertia\Response;

class MemberController extends Controller
{
    public function index(): Response
    {
        return Inertia::render('Master/Members/Index', [
            'members' => Member::orderBy('name')->get(),
        ]);
    }

    /**
     * Backs the free-text-capable member picker at Kasir checkout (web) --
     * same reasoning as SupplierController::search()/ItemController::search():
     * never return the full member list, only a capped, relevant slice for
     * whatever the cashier has typed so far. Only active members are
     * searchable here (an inactive member shouldn't be attachable to a NEW
     * sale, even though old sales that already reference one keep working
     * via the frozen name snapshot).
     */
    public function search(Request $request): JsonResponse
    {
        $query = trim((string) $request->input('q', ''));

        $members = Member::where('is_active', true)
            ->when($query !== '', function ($builder) use ($query) {
                $builder->where(function ($builder) use ($query) {
                    $builder->where('name', 'like', "%{$query}%")
                        ->orWhere('phone', 'like', "%{$query}%");
                });
            })
            ->orderBy('name')
            ->limit(20)
            ->get(['id', 'name', 'phone']);

        return response()->json($members);
    }

    public function create(): Response
    {
        return Inertia::render('Master/Members/Form', [
            'member' => null,
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        Member::create($this->validateData($request));

        return Redirect::route('master.members.index')->with('success', 'Member berhasil ditambahkan.');
    }

    public function edit(Member $member): Response
    {
        return Inertia::render('Master/Members/Form', [
            'member' => $member,
        ]);
    }

    public function update(Request $request, Member $member): RedirectResponse
    {
        $member->update($this->validateData($request));

        return Redirect::route('master.members.index')->with('success', 'Member berhasil diperbarui.');
    }

    /**
     * Blocked by the DB (restrictOnDelete on sales.member_id) if this
     * member already has sales — the admin should nonaktifkan (uncheck
     * "Aktif" on the edit form) instead, same pattern as Product/Item.
     */
    public function destroy(Member $member): RedirectResponse
    {
        return $this->deleteOrFail($member, 'master.members.index', 'Member');
    }

    private function validateData(Request $request): array
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'phone' => ['nullable', 'string', 'max:50'],
            'email' => ['nullable', 'email', 'max:255'],
            'address' => ['nullable', 'string', 'max:2000'],
            'is_active' => ['boolean'],
        ]);

        $data['is_active'] = $request->boolean('is_active');

        return $data;
    }
}
