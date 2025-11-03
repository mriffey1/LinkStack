<?php

namespace App\Http\Controllers;

use App\Models\Link;
use Illuminate\Http\Request;

class LinkAssociationController extends Controller
{
    public function edit($id)
    {
        $link = Link::findOrFail($id);

        // You can scope this to the current user if your Links table has user ownership.
        // If you have a user_id column: $allLinks = Link::where('user_id', auth()->id())->orderBy('title')->get(['id','title']);
        $allLinks = Link::orderBy('title')->get(['id','title']);

        $link->load('associatedLinks:id');

        return view('panel.link.associations', [
            'link' => $link,
            'allLinks' => $allLinks,
            'selectedAssociated' => $link->associatedLinks->pluck('id')->all(),
        ]);
    }

    public function update(Request $request, $id)
    {
        $link = Link::findOrFail($id);

        $validated = $request->validate([
            'associated_links'   => ['array'],
            'associated_links.*' => ['integer','exists:links,id','distinct','not_in:'.$link->id],
        ]);

        $link->associatedLinks()->sync($validated['associated_links'] ?? []);

        return redirect()
            ->route('links.associations.edit', $link->id)
            ->with('success', 'Associated links updated.');
    }
}
