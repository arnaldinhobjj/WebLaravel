<?php

namespace App\Http\Controllers;

use App\Models\AppSetting;
use App\Models\Ticket;
use App\Services\Email\Mailer;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

/**
 * Default addresses: who emails come from, and who else gets the "new ticket"
 * email for each ticket category.
 */
class EmailSettingsController extends Controller
{
    public function edit(Mailer $mailer): View
    {
        return view('emails.settings', [
            'sender' => $mailer->sender(),
            'categoryRecipients' => AppSetting::get('mail.category_recipients', []),
            'canEdit' => Gate::allows('email_settings.edit'),
        ]);
    }

    public function update(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'from_name' => ['required', 'string', 'max:80'],
            'from_address' => ['required', 'email:rfc', 'max:255'],
            'categories' => ['array'],
            'categories.*' => ['nullable', 'string', 'max:1000'],
        ]);

        $categoryRecipients = [];
        foreach (array_keys(Ticket::CATEGORIES) as $category) {
            $addresses = preg_split('/[\s,;]+/', (string) ($data['categories'][$category] ?? ''), -1, PREG_SPLIT_NO_EMPTY);
            foreach ($addresses as $address) {
                if (! filter_var($address, FILTER_VALIDATE_EMAIL)) {
                    throw ValidationException::withMessages(["categories.{$category}" => "“{$address}” isn’t a valid email address."]);
                }
            }
            if ($addresses !== []) {
                $categoryRecipients[$category] = array_values(array_unique(array_map('strtolower', $addresses)));
            }
        }

        AppSetting::put('mail.from_name', $data['from_name']);
        AppSetting::put('mail.from_address', $data['from_address']);
        AppSetting::put('mail.category_recipients', $categoryRecipients);

        return redirect()->route('email-settings.edit')->with('status', 'Email settings saved.');
    }
}
