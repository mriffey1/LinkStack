<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Cohensive\OEmbed\Facades\OEmbed;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Response;
use JeroenDesloovere\VCard\VCard;
use Illuminate\Validation\Rule;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\Mail;
use App\Mail\ReportSubmissionMail;
use GeoSot\EnvEditor\Facades\EnvEditor;

use Auth;
use DB;
use ZipArchive;
use File;

use App\Models\User;
use App\Models\Button;
use App\Models\Link;
use App\Models\LinkType;
use App\Models\UserData;


//Function tests if string starts with certain string (used to test for illegal strings)
function stringStartsWith($haystack, $needle, $case = true)
{
    if ($case) {
        return strpos($haystack, $needle, 0) === 0;
    }
    return stripos($haystack, $needle, 0) === 0;
}

//Function tests if string ends with certain string (used to test for illegal strings)
function stringEndsWith($haystack, $needle, $case = true)
{
    $expectedPosition = strlen($haystack) - strlen($needle);
    if ($case) {
        return strrpos($haystack, $needle, 0) === $expectedPosition;
    }
    return strripos($haystack, $needle, 0) === $expectedPosition;
}

class UserController extends Controller
{

    //Statistics of the number of clicks and links
    public function index()
    {
        $userId = Auth::user()->id;

        $littlelink_name = Auth::user()->littlelink_name;
        $userinfo = User::find($userId);

        $links = Link::where('user_id', $userId)->select('link')->count();
        $clicks = Link::where('user_id', $userId)->sum('click_number');
        $topLinks = Link::where('user_id', $userId)->orderby('click_number', 'desc')
            ->whereNotNull('link')->where('link', '<>', '')
            ->take(5)->get();

        $pageStats = [
            'visitors' => [
                'all' => visits('App\Models\User', $littlelink_name)->count(),
                'day' => visits('App\Models\User', $littlelink_name)->period('day')->count(),
                'week' => visits('App\Models\User', $littlelink_name)->period('week')->count(),
                'month' => visits('App\Models\User', $littlelink_name)->period('month')->count(),
                'year' => visits('App\Models\User', $littlelink_name)->period('year')->count(),
            ],
            'os' => visits('App\Models\User', $littlelink_name)->operatingSystems(),
            'referers' => visits('App\Models\User', $littlelink_name)->refs(),
            'countries' => visits('App\Models\User', $littlelink_name)->countries(),
        ];



        return view('studio/index', ['greeting' => $userinfo->name, 'toplinks' => $topLinks, 'links' => $links, 'clicks' => $clicks, 'pageStats' => $pageStats]);
    }

    // Show littlelink page. example => http://127.0.0.1:8000/+admin
    public function littlelink(request $request)
    {
        if (isset($request->useif)) {
            $littlelink_name = User::select('littlelink_name')->where('id', $request->littlelink)->value('littlelink_name');
            $id = $request->littlelink;
        } else {
            $littlelink_name = $request->littlelink;
            $id = User::select('id')->where('littlelink_name', $littlelink_name)->value('id');
        }

        if (empty($id)) {
            return abort(404);
        }

        $userinfo = User::select('id', 'name', 'littlelink_name', 'littlelink_description', 'theme', 'role', 'block')->where('id', $id)->first();
        $information = User::select('name', 'littlelink_name', 'littlelink_description', 'theme')->where('id', $id)->get();

        if ($userinfo->block == 'yes') {
            return abort(404);
        }

        $links = DB::table('links')
            ->join('buttons', 'buttons.id', '=', 'links.button_id')
            ->select('links.*', 'buttons.name')
            ->where('user_id', $id)
            ->orderBy('up_link', 'asc')
            ->orderBy('order', 'asc')
            ->get();

        // Decode and merge type_params onto each link object
        foreach ($links as $link) {
            if (!empty($link->type_params)) {
                $typeParams = json_decode($link->type_params, true);
                if (is_array($typeParams)) {
                    foreach ($typeParams as $key => $value) {
                        $link->$key = $value;
                    }
                }
            }
        }

        /* === associated-links: hide associated as big buttons & attach icons === */
        if (\Illuminate\Support\Facades\Schema::hasTable('link_associations')) {
            // 1) all link IDs currently on page
            $linkIds = $links->pluck('id');

            // 2) associations only for these links
            $pairs = DB::table('link_associations')
                ->whereIn('link_id', $linkIds)
                ->select('link_id', 'associated_link_id')
                ->get();

            // 3) which IDs should be hidden as big buttons?
            //    (only hide if that associated ID is also one of this user's page links)
            $assocIdsToHide = $pairs->pluck('associated_link_id')
                ->intersect($linkIds)   // keep only IDs that are on this page
                ->unique()
                ->values();

            // 4) drop those links from the main list
            $links = $links->reject(function ($l) use ($assocIdsToHide) {
                return $assocIdsToHide->contains($l->id);
            })->values();

            // 5) build mapping: link_id -> [associated_link_id, ...]
            $byLink = [];
            foreach ($pairs as $p) {
                $byLink[$p->link_id][] = $p->associated_link_id;
            }

            // 6) fetch associated link records (JOIN buttons to get platform/icon key)
            $assocIds = array_values(array_unique($pairs->pluck('associated_link_id')->all()));
            $assocLinks = collect();
            if (!empty($assocIds)) {
                $assocLinks = DB::table('links')
                    ->join('buttons', 'buttons.id', '=', 'links.button_id')
                    ->whereIn('links.id', $assocIds)
                    ->select(
                        'links.id',
                        'links.title',
                        'links.link',
                        'links.custom_icon',
                        'links.button_id',
                        'buttons.name as platform'
                    )
                    ->get()
                    ->keyBy('id');
            }

            // 7) attach 'associated' to each *remaining* main link, preserving order
            foreach ($links as $l) {
                $ids = $byLink[$l->id] ?? [];
                $l->associated = [];
                foreach ($ids as $aid) {
                    if (isset($assocLinks[$aid])) {
                        $l->associated[] = $assocLinks[$aid];
                    }
                }
            }
        } else {
            foreach ($links as $l) {
                $l->associated = [];
            }
        }
        /* === END === */


        return view('linkstack.linkstack', [
            'userinfo' => $userinfo,
            'information' => $information,
            'links' => $links,
            'littlelink_name' => $littlelink_name
        ]);
    }

    // Show littlelink page as home page if set in config
    public function littlelinkhome(request $request)
    {
        $littlelink_name = env('HOME_URL');
        $id = User::select('id')->where('littlelink_name', $littlelink_name)->value('id');

        if (empty($id)) {
            return abort(404);
        }

        $userinfo = User::select('id', 'name', 'littlelink_name', 'littlelink_description', 'theme', 'role', 'block')->where('id', $id)->first();
        $information = User::select('name', 'littlelink_name', 'littlelink_description', 'theme')->where('id', $id)->get();

        $links = DB::table('links')
            ->join('buttons', 'buttons.id', '=', 'links.button_id')
            ->select('links.*', 'buttons.name')
            ->where('user_id', $id)
            ->orderBy('up_link', 'asc')
            ->orderBy('order', 'asc')
            ->get();

        // Decode and merge type_params
        foreach ($links as $link) {
            if (!empty($link->type_params)) {
                $typeParams = json_decode($link->type_params, true);
                if (is_array($typeParams)) {
                    foreach ($typeParams as $key => $value) {
                        $link->$key = $value;
                    }
                }
            }
        }

        /* === associated-links: hide associated as big buttons & attach icons === */
        if (\Illuminate\Support\Facades\Schema::hasTable('link_associations')) {
            // 1) all link IDs currently on page
            $linkIds = $links->pluck('id');

            // 2) associations only for these links
            $pairs = DB::table('link_associations')
                ->whereIn('link_id', $linkIds)
                ->select('link_id', 'associated_link_id')
                ->get();

            // 3) which IDs should be hidden as big buttons?
            //    (only hide if that associated ID is also one of this user's page links)
            $assocIdsToHide = $pairs->pluck('associated_link_id')
                ->intersect($linkIds)   // keep only IDs that are on this page
                ->unique()
                ->values();

            // 4) drop those links from the main list
            $links = $links->reject(function ($l) use ($assocIdsToHide) {
                return $assocIdsToHide->contains($l->id);
            })->values();

            // 5) build mapping: link_id -> [associated_link_id, ...]
            $byLink = [];
            foreach ($pairs as $p) {
                $byLink[$p->link_id][] = $p->associated_link_id;
            }

            // 6) fetch associated link records (JOIN buttons to get platform/icon key)
            $assocIds = array_values(array_unique($pairs->pluck('associated_link_id')->all()));
            $assocLinks = collect();
            if (!empty($assocIds)) {
                $assocLinks = DB::table('links')
                    ->join('buttons', 'buttons.id', '=', 'links.button_id')
                    ->whereIn('links.id', $assocIds)
                    ->select(
                        'links.id',
                        'links.title',
                        'links.link',
                        'links.custom_icon',
                        'links.button_id',
                        'buttons.name as platform'
                    )
                    ->get()
                    ->keyBy('id');
            }

            // 7) attach 'associated' to each *remaining* main link, preserving order
            foreach ($links as $l) {
                $ids = $byLink[$l->id] ?? [];
                $l->associated = [];
                foreach ($ids as $aid) {
                    if (isset($assocLinks[$aid])) {
                        $l->associated[] = $assocLinks[$aid];
                    }
                }
            }
        } else {
            foreach ($links as $l) {
                $l->associated = [];
            }
        }
        /* === END === */



        return view('linkstack.linkstack', [
            'userinfo' => $userinfo,
            'information' => $information,
            'links' => $links,
            'littlelink_name' => $littlelink_name
        ]);
    }


    //Redirect to user page
    public function userRedirect(request $request)
    {
        $id = $request->id;
        $user = User::select('littlelink_name')->where('id', $id)->value('littlelink_name');

        if (empty($id)) {
            return abort(404);
        }

        if (empty($user)) {
            return abort(404);
        }

        return redirect(url('@' . $user));
    }

    //Show add/update form
// Show add/update form
    public function AddUpdateLink($id = 0)
    {
        $linkData = $id ? Link::find($id) : new Link(['typename' => 'link', 'id' => '0']);

        $data = [
            'LinkTypes' => LinkType::get(),
            'LinkData' => $linkData,
            'LinkID' => $id,
            'linkTypeID' => "predefined",
            'title' => "Predefined Site",
        ];

        // --- restrict/select "associated links" properly ---
        $userId = Auth::id();

        // (A) links already associated TO THIS link (these must appear + be preselected)
        $assocThis = [];
        if ($id) {
            $assocThis = DB::table('link_associations')
                ->where('link_id', $id)
                ->pluck('associated_link_id')
                ->all();
        }

        // (B) links associated to OTHER links (these should be hidden)
// limit to this user's links
        $userLinkIds = DB::table('links')->where('user_id', $userId)->pluck('id');
        $assocElsewhere = DB::table('link_associations')
            ->whereIn('link_id', $userLinkIds)   // only this user's main links
            ->when($id, fn($q) => $q->where('link_id', '!=', $id)) // exclude THIS link
            ->pluck('associated_link_id')
            ->unique()
            ->values()
            ->all();

        $allowedPlatforms = ['bluesky', 'twitter', 'instagram'];

        $allLinks = DB::table('links')
            ->join('buttons', 'buttons.id', '=', 'links.button_id')
            ->where('links.user_id', $userId)
            // only allowed platforms (case-insensitive)
            ->whereIn(DB::raw('LOWER(buttons.name)'), array_map('strtolower', $allowedPlatforms))
            // never show the link itself
            ->when($id, fn($q) => $q->where('links.id', '!=', $id))
            // hide links already associated to OTHER links, but allow ones associated to THIS link
            ->when(!empty($assocElsewhere), function ($q) use ($assocElsewhere, $assocThis) {
                $hide = array_values(array_diff($assocElsewhere, $assocThis));
                if (!empty($hide))
                    $q->whereNotIn('links.id', $hide);
            })
            ->select('links.id', 'links.title', 'links.link', 'buttons.name as platform')
            ->orderBy('links.up_link', 'asc')
            ->orderBy('links.order', 'asc')
            ->get();

        $selectedAssoc = $assocThis;

        $data['typename'] = $linkData->type ?? 'predefined';
        $data['allLinks'] = $allLinks;
        $data['selectedAssoc'] = $selectedAssoc;


        return view('studio/edit-link', $data);
    }


    // Save add link
    public function saveLink(Request $request)
    {
        // Step 1: Validate Request
        // $request->validate(['link' => 'sometimes|url']);

        // Step 2: Determine Link Type and Title
        $linkType = LinkType::findByTypename($request->typename);
        $LinkTitle = $request->title;
        $LinkURL = $request->link;

        // Step 3: Load Link Type Logic
        if ($request->typename == 'predefined' || $request->typename == 'link') {
            $button_id = ($request->typename == 'link') ? ($request->GetSiteIcon == 1 ? 2 : 1) : null;
            $button = ($request->typename != 'link') ? Button::where('name', $request->button)->first() : null;

            $linkData = [
                'link' => $LinkURL,
                'title' => $LinkTitle ?? $button?->alt,
                'user_id' => Auth::user()->id,
                'button_id' => $button?->id ?? $button_id,
                'type' => $request->typename
            ];
        } else {
            $linkTypePath = base_path("blocks/{$linkType->typename}/handler.php");
            if (file_exists($linkTypePath)) {
                include $linkTypePath;
                $result = handleLinkType($request, $linkType);
                $rules = $result['rules'];
                $linkData = $result['linkData'];

                $validator = Validator::make($request->all(), $rules);
                if ($validator->fails()) {
                    return back()->withErrors($validator)->withInput();
                }

                $linkData['button_id'] = $linkData['button_id'] ?? 1;
                $linkData['type'] = $linkType->typename;
            } else {
                abort(404, "Link type logic not found.");
            }
        }

        // Step 4: Handle Custom Parameters (same as before)

        // Step 5: User and Button Information
        $userId = Auth::user()->id;
        $button = Button::where('name', $request->button)->first();
        if ($button && empty($LinkTitle))
            $LinkTitle = $button->alt;

        // Step 6: Prepare Link Data (handled above)

        // Step 7: Save or Update Link
        $OrigLink = Link::find($request->linkid);
        $linkColumns = Schema::getColumnListing('links');
        $filteredLinkData = array_intersect_key($linkData, array_flip($linkColumns));
        $customParams = array_diff_key($linkData, $filteredLinkData);

        if (isset($linkType->custom_html)) {
            $customParams['custom_html'] = $linkType->custom_html;
        }
        if (isset($linkType->ignore_container)) {
            $customParams['ignore_container'] = $linkType->ignore_container;
        }
        if (isset($linkType->include_libraries)) {
            $customParams['include_libraries'] = $linkType->include_libraries;
        }

        $filteredLinkData['type_params'] = json_encode($customParams);

        if ($OrigLink) {
            $currentValues = $OrigLink->getAttributes();
            $nonNullFilteredLinkData = array_filter($filteredLinkData, fn($v) => !is_null($v));
            $updatedValues = array_merge($currentValues, $nonNullFilteredLinkData);
            $OrigLink->update($updatedValues);
            $message = "Link updated";
        } else {
            $link = new Link($filteredLinkData);
            $link->user_id = $userId;
            $link->save();
            $message = "Link added";
        }

        /* -------- Step 7.5: sync associated links (pivot) -------- */
        // the form must send: <select name="associated_links[]" multiple>...</select>
        $linkId = $OrigLink ? $OrigLink->id : $link->id;

        $allowedPlatforms = ['bluesky', 'twitter', 'instagram'];
        $selected = (array) $request->input('associated_links', []);

        // Re-filter selection: this user, not self, allowed platforms only
        $selected = DB::table('links')
            ->join('buttons', 'buttons.id', '=', 'links.button_id')
            ->where('links.user_id', $userId)
            ->whereIn('links.id', $selected)
            ->where('links.id', '!=', $linkId)
            ->whereIn('buttons.name', $allowedPlatforms)
            ->pluck('links.id')
            ->all();

        // Optional hardening: remove anything already associated elsewhere
        $alreadyAssociated = DB::table('link_associations')->pluck('associated_link_id')->toArray();
        if (!empty($alreadyAssociated)) {
            $selected = array_values(array_diff($selected, $alreadyAssociated));
        }


        if (!empty($selected)) {
            $now = now();
            $rows = array_map(fn($aid) => [
                'link_id' => $linkId,
                'associated_link_id' => $aid,
                'created_at' => $now,
            ], $selected);

            DB::table('link_associations')->insert($rows);
        }
        /* -------- end Step 7.5 -------- */

        // Step 8: Redirect
        $redirectUrl = $request->input('param') == 'add_more' ? 'studio/add-link' : 'studio/links';
        return Redirect($redirectUrl)->with('success', $message);
    }


    public function sortLinks(Request $request)
    {
        $linkOrders = $request->input("linkOrders", []);
        $currentPage = $request->input("currentPage", 1);
        $perPage = $request->input("perPage", 0);

        if ($perPage == 0) {
            $currentPage = 1;
        }

        $linkOrders = array_unique(array_filter($linkOrders));
        if (!$linkOrders || $currentPage < 1) {
            return response()->json([
                'status' => 'ERROR',
            ]);
        }

        $newOrder = $perPage * ($currentPage - 1);
        $linkNewOrders = [];
        foreach ($linkOrders as $linkId) {
            if ($linkId < 0) {
                continue;
            }

            $linkNewOrders[$linkId] = $newOrder;
            Link::where("id", $linkId)
                ->update([
                    'order' => $newOrder
                ]);
            $newOrder++;
        }

        return response()->json([
            'status' => 'OK',
            'linkOrders' => $linkNewOrders,
        ]);
    }


    //Count the number of clicks and redirect to link
    public function clickNumber(request $request)
    {
        $linkId = $request->id;

        if (substr($linkId, -1) == '+') {
            $linkWithoutPlus = str_replace('+', '', $linkId);
            return redirect(url('info/' . $linkWithoutPlus));
        }

        $link = Link::find($linkId);

        if (empty($link)) {
            return abort(404);
        }

        $link = $link->link;

        if (empty($linkId)) {
            return abort(404);
        }

        Link::where('id', $linkId)->increment('click_number', 1);

        $response = redirect()->away($link);
        $response->header('X-Robots-Tag', 'noindex, nofollow');

        return $response;
    }

    //Download Vcard
    public function vcard(request $request)
    {
        $linkId = $request->id;

        // Find the link with the specified ID
        $link = Link::findOrFail($linkId);

        $json = $link->link;

        // Decode the JSON to a PHP array
        $data = json_decode($json, true);

        // Create a new vCard object
        $vcard = new VCard();

        // Set the vCard properties from the $data array
        $vcard->addName($data['last_name'], $data['first_name'], $data['middle_name'], $data['prefix'], $data['suffix']);
        $vcard->addCompany($data['organization']);
        $vcard->addJobtitle($data['vtitle']);
        $vcard->addRole($data['role']);
        $vcard->addEmail($data['email']);
        $vcard->addEmail($data['work_email'], 'WORK');
        $vcard->addURL($data['work_url'], 'WORK');
        $vcard->addPhoneNumber($data['home_phone'], 'HOME');
        $vcard->addPhoneNumber($data['work_phone'], 'WORK');
        $vcard->addPhoneNumber($data['cell_phone'], 'CELL');
        $vcard->addAddress($data['home_address_street'], '', $data['home_address_city'], $data['home_address_state'], $data['home_address_zip'], $data['home_address_country'], 'HOME');
        $vcard->addAddress($data['work_address_street'], '', $data['work_address_city'], $data['work_address_state'], $data['work_address_zip'], $data['work_address_country'], 'WORK');


        // $vcard->addPhoto(base_path('img/1.png'));

        // Generate the vCard file contents
        $file_contents = $vcard->getOutput();

        // Set the file headers for download
        $headers = [
            'Content-Type' => 'text/x-vcard',
            'Content-Disposition' => 'attachment; filename="contact.vcf"'
        ];

        Link::where('id', $linkId)->increment('click_number', 1);

        // Return the file download response
        return response()->make($file_contents, 200, $headers);

    }

    //Show link, click number, up link in links page
    public function showLinks()
    {
        $userId = Auth::user()->id;
        $data['pagePage'] = 10;

        $data['links'] = Link::select()->where('user_id', $userId)->orderBy('up_link', 'asc')->orderBy('order', 'asc')->paginate(99999);
        return view('studio/links', $data);
    }

    //Delete link
    public function deleteLink(request $request)
    {
        $linkId = $request->id;

        Link::where('id', $linkId)->delete();

        $directory = base_path("assets/favicon/icons");
        $files = scandir($directory);
        foreach ($files as $file) {
            if (strpos($file, $linkId . ".") !== false) {
                $pathinfo = pathinfo($file, PATHINFO_EXTENSION);
            }
        }
        if (isset($pathinfo)) {
            try {
                File::delete(base_path("assets/favicon/icons") . "/" . $linkId . "." . $pathinfo);
            } catch (exception $e) {
            }
        }

        return redirect('/studio/links');
    }

    //Delete icon
    public function clearIcon(request $request)
    {
        $linkId = $request->id;

        $directory = base_path("assets/favicon/icons");
        $files = scandir($directory);
        foreach ($files as $file) {
            if (strpos($file, $linkId . ".") !== false) {
                $pathinfo = pathinfo($file, PATHINFO_EXTENSION);
            }
        }
        if (isset($pathinfo)) {
            try {
                File::delete(base_path("assets/favicon/icons") . "/" . $linkId . "." . $pathinfo);
            } catch (exception $e) {
            }
        }

        return redirect('/studio/links');
    }

    //Raise link on the littlelink page
    public function upLink(request $request)
    {
        $linkId = $request->id;
        $upLink = $request->up;

        if ($upLink == 'yes') {
            $up = 'no';
        } elseif ($upLink == 'no') {
            $up = 'yes';
        }

        Link::where('id', $linkId)->update(['up_link' => $up]);

        return back();
    }

    //Show link to edit
    public function showLink(request $request)
    {
        $linkId = $request->id;

        $link = Link::where('id', $linkId)->value('link');
        $title = Link::where('id', $linkId)->value('title');
        $order = Link::where('id', $linkId)->value('order');
        $custom_css = Link::where('id', $linkId)->value('custom_css');
        $buttonId = Link::where('id', $linkId)->value('button_id');
        $buttonName = Button::where('id', $buttonId)->value('name');

        $buttons = Button::select('id', 'name')->orderBy('name', 'asc')->get();

        return view('studio/edit-link', ['custom_css' => $custom_css, 'buttonId' => $buttonId, 'buttons' => $buttons, 'link' => $link, 'title' => $title, 'order' => $order, 'id' => $linkId, 'buttonName' => $buttonName]);
    }

    //Show custom CSS + custom icon
    public function showCSS(request $request)
    {
        $linkId = $request->id;

        $link = Link::where('id', $linkId)->value('link');
        $title = Link::where('id', $linkId)->value('title');
        $order = Link::where('id', $linkId)->value('order');
        $custom_css = Link::where('id', $linkId)->value('custom_css');
        $custom_icon = Link::where('id', $linkId)->value('custom_icon');
        $buttonId = Link::where('id', $linkId)->value('button_id');

        $buttons = Button::select('id', 'name')->get();

        return view('studio/button-editor', ['custom_icon' => $custom_icon, 'custom_css' => $custom_css, 'buttonId' => $buttonId, 'buttons' => $buttons, 'link' => $link, 'title' => $title, 'order' => $order, 'id' => $linkId]);
    }

    // Save edit link
    public function editLink(Request $request)
    {
        $request->validate([
            'link' => 'required|exturl',
            'title' => 'required',
            'button' => 'required',
        ]);

        // --- Normalize link format ---
        if (
            stringStartsWith($request->link, 'http://') == 'true'
            || stringStartsWith($request->link, 'https://') == 'true'
            || stringStartsWith($request->link, 'mailto:') == 'true'
        ) {
            $link1 = $request->link;
        } else {
            $link1 = 'https://' . $request->link;
        }

        if (stringEndsWith($request->link, '/') == 'true')
            $link = rtrim($link1, "/ ");
        else
            $link = $link1;

        $title = $request->title;
        $order = $request->order;
        $button = $request->button;
        $linkId = $request->id;
        $userId = Auth::id();

        // --- Get button ID ---
        $buttonId = Button::select('id')->where('name', $button)->value('id');

        // --- Update link record ---
        Link::where('id', $linkId)->update([
            'link' => $link,
            'title' => $title,
            'order' => $order,
            'button_id' => $buttonId,
        ]);

        /* --- Step 2: sync associated links --- */
        // The form must include: <select name="associated_links[]" multiple>
        $allowedPlatforms = ['bluesky', 'twitter', 'instagram'];
        $selected = (array) $request->input('associated_links', []);

        $selected = DB::table('links')
            ->join('buttons', 'buttons.id', '=', 'links.button_id')
            ->where('links.user_id', $userId)
            ->whereIn('links.id', $selected)
            ->where('links.id', '!=', $linkId)
            ->whereIn('buttons.name', $allowedPlatforms)
            ->pluck('links.id')
            ->all();

        // Optional hardening: remove anything already associated elsewhere
        $alreadyAssociated = DB::table('link_associations')->pluck('associated_link_id')->toArray();
        if (!empty($alreadyAssociated)) {
            $selected = array_values(array_diff($selected, $alreadyAssociated));
        }


        // Insert new ones if any selected
        if (!empty($selected)) {
            $now = now();
            $rows = array_map(fn($aid) => [
                'link_id' => $linkId,
                'associated_link_id' => $aid,
                'created_at' => $now,
            ], $selected);

            DB::table('link_associations')->insert($rows);
        }
        /* --- End sync --- */

        return redirect('/studio/links')->with('success', 'Link updated successfully');
    }


    //Save edit custom CSS + custom icon
    public function editCSS(request $request)
    {
        $linkId = $request->id;
        $custom_icon = $request->custom_icon;
        $custom_css = $request->custom_css;

        if ($request->custom_css == "" and $request->custom_icon = !"") {
            Link::where('id', $linkId)->update(['custom_icon' => $custom_icon]);
        } elseif ($request->custom_icon == "" and $request->custom_css = !"") {
            Link::where('id', $linkId)->update(['custom_css' => $custom_css]);
        } else {
            Link::where('id', $linkId)->update([]);
        }
        return Redirect('#result');
    }

    //Show littlelinke page for edit
    public function showPage(request $request)
    {
        $userId = Auth::user()->id;

        $data['pages'] = User::where('id', $userId)->select('littlelink_name', 'littlelink_description', 'image', 'name')->get();

        return view('/studio/page', $data);
    }

    //Save littlelink page (name, description, logo)
    public function editPage(Request $request)
    {
        $userId = Auth::user()->id;
        $littlelink_name = Auth::user()->littlelink_name;

        $validator = Validator::make($request->all(), [
            'littlelink_name' => [
                'sometimes',
                'max:255',
                'string',
                'isunique:users,id,' . $userId,
            ],
            'name' => 'sometimes|max:255|string',
            'image' => 'sometimes|image|mimes:jpeg,jpg,png,webp|max:2048', // Max file size: 2MB
        ], [
            'littlelink_name.unique' => __('messages.That handle has already been taken'),
            'image.image' => __('messages.The selected file must be an image'),
            'image.mimes' => __('messages.The image must be') . ' JPEG, JPG, PNG, webP.',
            'image.max' => __('messages.The image size should not exceed 2MB'),
        ]);

        if ($validator->fails()) {
            return redirect('/studio/page')->withErrors($validator)->withInput();
        }

        $profilePhoto = $request->file('image');
        $pageName = $request->littlelink_name;
        $pageDescription = strip_tags($request->pageDescription, '<a><p><strong><i><ul><ol><li><blockquote><h2><h3><h4>');
        $pageDescription = preg_replace("/<a([^>]*)>/i", "<a $1 rel=\"noopener noreferrer nofollow\">", $pageDescription);
        $pageDescription = strip_tags_except_allowed_protocols($pageDescription);
        $name = $request->name;
        $checkmark = $request->checkmark;
        $sharebtn = $request->sharebtn;
        $tablinks = $request->tablinks;

        if (env('HOME_URL') !== '' && $pageName != $littlelink_name && $littlelink_name == env('HOME_URL')) {
            EnvEditor::editKey('HOME_URL', $pageName);
        }

        User::where('id', $userId)->update([
            'littlelink_name' => $pageName,
            'littlelink_description' => $pageDescription,
            'name' => $name
        ]);

        if ($request->hasFile('image')) {

            // Delete the user's current avatar if it exists
            while (findAvatar($userId) !== "error.error") {
                $avatarName = findAvatar($userId);
                unlink(base_path($avatarName));
            }

            $fileName = $userId . '_' . time() . "." . $profilePhoto->extension();
            $profilePhoto->move(base_path('assets/img'), $fileName);
        }

        if ($checkmark == "on") {
            UserData::saveData($userId, 'checkmark', true);
        } else {
            UserData::saveData($userId, 'checkmark', false);
        }

        if ($sharebtn == "on") {
            UserData::saveData($userId, 'disable-sharebtn', false);
        } else {
            UserData::saveData($userId, 'disable-sharebtn', true);
        }

        if ($tablinks == "on") {
            UserData::saveData($userId, 'links-new-tab', true);
        } else {
            UserData::saveData($userId, 'links-new-tab', false);
        }

        return Redirect('/studio/page');
    }

    //Upload custom theme background image
    public function themeBackground(Request $request)
    {
        $userId = Auth::user()->id;
        $littlelink_name = Auth::user()->littlelink_name;

        $request->validate([
            'image' => 'required|image|mimes:jpeg,jpg,png,webp,gif|max:2048', // Max file size: 2MB
        ], [
            'image.required' => __('messages.Please select an image'),
            'image.image' => __('messages.The selected file must be an image'),
            'image.mimes' => __('messages.The image must be') . ' JPEG, JPG, PNG, webP, GIF.',
            'image.max' => __('messages.The image size should not exceed 2MB'),
        ]);

        $customBackground = $request->file('image');

        if ($customBackground) {
            $directory = base_path('assets/img/background-img/');
            $files = scandir($directory);
            $pathinfo = "error.error";
            foreach ($files as $file) {
                if (strpos($file, $userId . '.') !== false) {
                    $pathinfo = $userId . "." . pathinfo($file, PATHINFO_EXTENSION);
                }
            }

            // Delete the user's current background image if it exists
            while (findBackground($userId) !== "error.error") {
                $avatarName = "assets/img/background-img/" . findBackground(Auth::id());
                unlink(base_path($avatarName));
            }

            $fileName = $userId . '_' . time() . "." . $customBackground->extension();
            $customBackground->move(base_path('assets/img/background-img/'), $fileName);

            if (extension_loaded('imagick')) {
                $imagePath = base_path('assets/img/background-img/') . $fileName;
                $image = new \Imagick($imagePath);
                $image->stripImage();
                $image->writeImage($imagePath);
            }

            return redirect('/studio/theme');
        }

        return redirect('/studio/theme')->with('error', 'Please select a valid image file.');
    }

    //Delete custom background image
    public function removeBackground()
    {
        $userId = Auth::user()->id;

        // Delete the user's current background image if it exists
        while (findBackground($userId) !== "error.error") {
            $avatarName = "assets/img/background-img/" . findBackground(Auth::id());
            unlink(base_path($avatarName));
        }

        return back();
    }


    //Show custom theme
    public function showTheme(request $request)
    {
        $userId = Auth::user()->id;

        $data['pages'] = User::where('id', $userId)->select('littlelink_name', 'theme')->get();

        return view('/studio/theme', $data);
    }

    //Save custom theme
    public function editTheme(request $request)
    {
        $request->validate([
            'zip' => 'sometimes|mimes:zip',
        ]);

        $userId = Auth::user()->id;

        $zipfile = $request->file('zip');

        $theme = $request->theme;
        $message = "";

        User::where('id', $userId)->update(['theme' => $theme]);



        if (!empty($zipfile) && Auth::user()->role == 'admin') {

            $themesPath = base_path('themes');
            $tmpPath = base_path() . '/themes/temp.zip';
            $zipfile->move($themesPath, "temp.zip");

            $zip = new ZipArchive;
            $zip->open($tmpPath);
            $zip->extractTo($themesPath);
            $zip->close();
            unlink($tmpPath);

            // Removes version numbers from folder.

            $regex = '/[0-9.-]/';
            $files = scandir($themesPath);
            $files = array_diff($files, array('.', '..'));

            foreach ($files as $file) {

                $basename = basename($file);
                $filePath = $themesPath . '/' . $basename;

                if (!is_dir($filePath)) {

                    try {
                        File::delete($filePath);
                    } catch (exception $e) {
                    }

                }

                if (preg_match($regex, $basename)) {

                    $newBasename = preg_replace($regex, '', $basename);
                    $newPath = $themesPath . '/' . $newBasename;
                    File::copyDirectory($filePath, $newPath);
                    File::deleteDirectory($filePath);

                }

            }

        }


        return Redirect('/studio/theme')->with("success", $message);
    }

    //Show user (name, email, password)
    public function showProfile(request $request)
    {
        $userId = Auth::user()->id;

        $data['profile'] = User::where('id', $userId)->select('name', 'email', 'role')->get();

        return view('/studio/profile', $data);
    }

    //Save user (name, email, password)
    public function editProfile(request $request)
    {
        $request->validate([
            'name' => 'sometimes|required|unique:users',
            'email' => 'sometimes|required|email|unique:users',
            'password' => 'sometimes|min:8',
        ]);

        $userId = Auth::user()->id;

        $name = $request->name;
        $email = $request->email;
        $password = Hash::make($request->password);

        if ($request->name != '') {
            User::where('id', $userId)->update(['name' => $name]);
        } elseif ($request->email != '') {
            User::where('id', $userId)->update(['email' => $email]);
        } elseif ($request->password != '') {
            User::where('id', $userId)->update(['password' => $password]);
            Auth::logout();
        }
        return back();
    }

    //Show user theme credit page
    public function theme(request $request)
    {
        $littlelink_name = $request->littlelink;
        $id = User::select('id')->where('littlelink_name', $littlelink_name)->value('id');

        if (empty($id)) {
            return abort(404);
        }

        $userinfo = User::select('name', 'littlelink_name', 'littlelink_description', 'theme')->where('id', $id)->first();
        $information = User::select('name', 'littlelink_name', 'littlelink_description', 'theme')->where('id', $id)->get();

        $links = DB::table('links')->join('buttons', 'buttons.id', '=', 'links.button_id')->select('links.link', 'links.id', 'links.button_id', 'links.title', 'links.custom_css', 'links.custom_icon', 'buttons.name')->where('user_id', $id)->orderBy('up_link', 'asc')->orderBy('order', 'asc')->get();

        return view('components/theme', ['userinfo' => $userinfo, 'information' => $information, 'links' => $links, 'littlelink_name' => $littlelink_name]);
    }

    //Delete existing user
    public function deleteUser(request $request)
    {

        // echo $request->id;
        // echo "<br>";
        // echo Auth::id();
        $id = $request->id;

        if ($id == Auth::id() and $id != "1") {

            Link::where('user_id', $id)->delete();

            $user = User::find($id);

            Schema::disableForeignKeyConstraints();
            $user->forceDelete();
            Schema::enableForeignKeyConstraints();
        }

        return redirect('/');
    }

    //Delete profile picture
    public function delProfilePicture()
    {
        $userId = Auth::user()->id;

        // Delete the user's current avatar if it exists
        while (findAvatar($userId) !== "error.error") {
            $avatarName = findAvatar($userId);
            unlink(base_path($avatarName));
        }

        return back();
    }

    //Export user links
    public function exportLinks(request $request)
    {
        $userId = Auth::id();
        $user = User::find($userId);
        $links = Link::where('user_id', $userId)->get();

        if (!$user) {
            // handle the case where the user is null
            return response()->json(['message' => 'User not found'], 404);
        }

        $userData['links'] = $links->toArray();

        $domain = $_SERVER['HTTP_HOST'];
        $date = date('Y-m-d_H-i-s');
        $fileName = "links-$domain-$date.json";
        $headers = [
            'Content-Type' => 'application/json',
            'Content-Disposition' => 'attachment; filename="' . $fileName . '"',
        ];
        return response()->json($userData, 200, $headers);

        return back();
    }

    //Export all user data
    public function exportAll(Request $request)
    {
        $userId = Auth::id();
        $user = User::find($userId);
        $links = Link::where('user_id', $userId)->get();

        if (!$user) {
            // handle the case where the user is null
            return response()->json(['message' => 'User not found'], 404);
        }

        $userData = $user->toArray();
        $userData['links'] = $links->toArray();

        if (file_exists(base_path(findAvatar($userId)))) {
            $imagePath = base_path(findAvatar($userId));
            $imageData = base64_encode(file_get_contents($imagePath));
            $userData['image_data'] = $imageData;

            $imageExtension = pathinfo($imagePath, PATHINFO_EXTENSION);
            $userData['image_extension'] = $imageExtension;
        }

        $domain = $_SERVER['HTTP_HOST'];
        $date = date('Y-m-d_H-i-s');
        $fileName = "user_data-$domain-$date.json";
        $headers = [
            'Content-Type' => 'application/json',
            'Content-Disposition' => 'attachment; filename="' . $fileName . '"',
        ];
        return response()->json($userData, 200, $headers);

        return back();
    }

    public function importData(Request $request)
    {
        try {
            // Get the JSON data from the uploaded file
            if (!$request->hasFile('import') || !$request->file('import')->isValid()) {
                throw new \Exception('File not uploaded or is faulty');
            }
            $file = $request->file('import');
            $jsonString = $file->get();
            $userData = json_decode($jsonString, true);

            // Update the authenticated user's profile data if defined in the JSON file
            $user = auth()->user();
            if (isset($userData['name'])) {
                $user->name = $userData['name'];
            }

            if (isset($userData['littlelink_description'])) {
                $sanitizedText = $userData['littlelink_description'];
                $sanitizedText = strip_tags($sanitizedText, '<a><p><strong><i><ul><ol><li><blockquote><h2><h3><h4>');
                $sanitizedText = preg_replace("/<a([^>]*)>/i", "<a $1 rel=\"noopener noreferrer nofollow\">", $sanitizedText);
                $sanitizedText = strip_tags_except_allowed_protocols($sanitizedText);
                $user->littlelink_description = $sanitizedText;
            }

            if (isset($userData['image_data'])) {

                $allowedExtensions = array('jpeg', 'jpg', 'png', 'webp');
                $userExtension = strtolower($userData['image_extension']);

                if (in_array($userExtension, $allowedExtensions)) {
                    // Decode the image data from Base64
                    $imageData = base64_decode($userData['image_data']);

                    // Delete the user's current avatar if it exists
                    while (findAvatar(Auth::id()) !== "error.error") {
                        $avatarName = findAvatar(Auth::id());
                        unlink(base_path($avatarName));
                    }

                    // Save the image to the correct path with the correct file name and extension
                    $filename = $user->id . '.' . $userExtension;
                    file_put_contents(base_path('assets/img/' . $filename), $imageData);

                    // Update the user's image field with the correct file name
                    $user->image = $filename;
                }
            }

            $user->save();

            // Delete all links for the authenticated user
            Link::where('user_id', $user->id)->delete();

            // Loop through each link in $userData and create a new link for the user
            foreach ($userData['links'] as $linkData) {

                $validatedData = Validator::make($linkData, [
                    'link' => 'nullable|exturl',
                ]);

                if ($validatedData->fails()) {
                    throw new \Exception('Invalid link');
                }

                $newLink = new Link();

                // Copy over the link data from $linkData to $newLink
                $newLink->button_id = $linkData['button_id'];
                $newLink->link = $linkData['link'];

                // Sanitize the title
                if ($linkData['button_id'] == 93) {
                    $sanitizedText = strip_tags($linkData['title'], '<a><p><strong><i><ul><ol><li><blockquote><h2><h3><h4>');
                    $sanitizedText = preg_replace("/<a([^>]*)>/i", "<a $1 rel=\"noopener noreferrer nofollow\">", $sanitizedText);
                    $sanitizedText = strip_tags_except_allowed_protocols($sanitizedText);

                    $newLink->title = $sanitizedText;
                } else {
                    $newLink->title = $linkData['title'];
                }

                $newLink->order = $linkData['order'];
                $newLink->click_number = 0;
                $newLink->up_link = $linkData['up_link'];
                $newLink->custom_css = $linkData['custom_css'];
                $newLink->custom_icon = $linkData['custom_icon'];
                $newLink->type = $linkData['type'];
                $newLink->type_params = $linkData['type_params'];

                // Set the user ID to the current user's ID
                $newLink->user_id = $user->id;

                // Save the new link to the database
                $newLink->save();
            }
            return redirect('studio/profile')->with('success', __('messages.Profile updated successfully!'));
        } catch (\Exception $e) {
            return redirect('studio/profile')->with('error', __('messages.An error occurred while updating your profile.'));
        }
    }


    // Hanle reports
    function report(Request $request)
    {
        $formData = $request->all();

        try {
            Mail::to(env('ADMIN_EMAIL'))->send(new ReportSubmissionMail($formData));

            return redirect('report')->with('success', __('messages.report_success'));
        } catch (\Exception $e) {
            return redirect()->back()->with('error', __('messages.report_error'));
        }
    }

    //Edit/save page icons
    public function editIcons(Request $request)
    {
        $inputKeys = array_keys($request->except('_token'));

        $validationRules = [];

        foreach ($inputKeys as $platform) {
            $validationRules[$platform] = 'nullable|exturl|max:255';
        }

        $request->validate($validationRules);

        foreach ($inputKeys as $platform) {
            $link = $request->input($platform);

            if (!empty($link)) {
                $iconId = $this->searchIcon($platform);

                if (!is_null($iconId)) {
                    $this->updateIcon($platform, $link);
                } else {
                    $this->addIcon($platform, $link);
                }
            }
        }

        return redirect('studio/links#icons');
    }

    private function searchIcon($icon)
    {
        return DB::table('links')
            ->where('user_id', Auth::id())
            ->where('title', $icon)
            ->where('button_id', 94)
            ->value('id');
    }

    private function addIcon($icon, $link)
    {
        $userId = Auth::user()->id;
        $links = new Link;
        $links->link = $link;
        $links->user_id = $userId;
        $links->title = $icon;
        $links->button_id = '94';
        $links->save();
        $links->order = ($links->id - 1);
        $links->save();
    }

    private function updateIcon($icon, $link)
    {
        Link::where('id', $this->searchIcon($icon))->update([
            'button_id' => 94,
            'link' => $link,
            'title' => $icon
        ]);
    }
}