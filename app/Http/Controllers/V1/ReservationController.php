<?php

namespace App\Http\Controllers\V1;

use App\Enums\ReservationStatusEnum;
use App\Enums\UserRoleEnum;
use App\Http\Controllers\Controller;
use App\Http\Requests\ReservationRequest;
use App\Http\Resources\ReservationResource;
use App\Models\Asset;
use App\Models\Reservation;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Mail;
use App\Mail\ReservationStoreMail;

class ReservationController extends Controller
{

    /**
     * __construct
     *
     * @return void
     */
    public function __construct()
    {
        $this->middleware('can:isEmployee')->only(['store', 'update']);
        $this->authorizeResource(Reservation::class);
    }
    /**
     * index
     *
     * @param  mixed $request
     * @return void
     */
    public function index(Request $request)
    {
        $records = Reservation::query();
        $sortBy = $request->input('sortBy', 'created_at');
        $orderBy = $request->input('orderBy', 'desc');
        $perPage = $request->input('perPage', 10);
        $perPage = $this->getPaginationSize($perPage);

        //search
        if ($request->has('search')) {
            $records->where('title', 'LIKE', '%' . $request->input('search') . '%');
        }

        //filter
        $records = $this->filterList($request, $records);

        //order
        $records = $this->sortBy($sortBy, $orderBy, $records);
        if ($request->user()->hasRole(UserRoleEnum::employee_reservasi())) {
            $records->byUser($request->user());
        }

        return ReservationResource::collection($records->paginate($perPage));
    }

    /**
     * store
     *
     * @param  mixed $request
     * @return void
     */
    public function store(ReservationRequest $request)
    {
        $asset = Asset::find($request->asset_id);
        $reservation = Reservation::create($request->all() + [
            'user_id_reservation' => $request->user()->uuid,
            'user_fullname' => $request->user()->name,
            'username' => $request->user()->username,
            'email' => $request->user()->email,
            'asset_name' => $asset->name,
            'asset_description' => $asset->description,
            'approval_status' => ReservationStatusEnum::already_approved(),
        ]);

        Mail::to(config('mail.admin_address'))->send(new ReservationStoreMail($reservation));
        return new ReservationResource($reservation);
    }

    /**
     * update
     *
     * @param  mixed $request
     * @return void
     */
    public function update(ReservationRequest $request, Reservation $reservation)
    {
        abort_if($reservation->is_not_yet_approved, 500, __('validation.asset_modified'));
        abort_if($reservation->check_time_edit_valid, 500, __('validation.asset_modified_time'));
        $asset = Asset::find($request->asset_id);
        $reservation->fill($request->all() + [
            'asset_name' => $asset->name,
            'asset_description' => $asset->description,
            'user_id_updated' => $request->user()->uuid
        ])->save();
        return new ReservationResource($reservation);
    }

    /**
     * destroy
     *
     * @param  mixed $reservation
     * @return void
     */
    public function destroy(Reservation $reservation)
    {
        abort_if($reservation->is_not_yet_approved, 500, __('validation.asset_modified'));
        $reservation->delete();
        return response()->json(['message' => 'Reservation record deleted.']);
    }

    /**
     * show
     *
     * @param  mixed $reservation
     * @return void
     */
    public function show(Reservation $reservation)
    {
        return new ReservationResource($reservation);
    }

    /**
     * getPaginationSize
     *
     * @param  mixed $perPage
     * @return void
     */
    protected function getPaginationSize($perPage)
    {
        $perPageAllowed = [50, 100, 500];

        if (in_array($perPage, $perPageAllowed)) {
            return $perPage;
        }
        return 10;
    }

    /**
     * filterList
     *
     * @param  mixed $request
     * @param  mixed $records
     * @return void
     */
    protected function filterList(Request $request, $records)
    {
        if ($request->has('asset_id')) {
            $records->where('asset_id', $request->input('asset_id'));
        }
        if ($request->has('approval_status')) {
            $records->where('approval_status', 'LIKE', '%' . $request->input('approval_status') . '%');
        }
        if ($request->has('start_date')) {
            $records->whereDate('date', '>=', Carbon::parse($request->input('start_date')));
        }
        if ($request->has('end_date')) {
            $records->whereDate('date', '<=', Carbon::parse($request->input('end_date')));
        }
        return $records;
    }

    /**
     * sortBy
     *
     * @param  mixed $sortBy
     * @param  mixed $orderBy
     * @param  mixed $records
     * @return void
     */
    protected function sortBy($sortBy, $orderBy, $records)
    {
        if ($sortBy === 'reservation_time') {
            return $records->orderBy('date', $orderBy)
                ->orderBy('start_time', $orderBy)
                ->orderBy('end_time', $orderBy);
        }
        $sortByAllowed = ['user_fullname', 'username', 'title', 'approval_status', 'date'];
        if (!in_array($sortBy, $sortByAllowed)) {
            $sortBy = 'created_at';
        }
        return $records->orderBy($sortBy, $orderBy);
    }
}
