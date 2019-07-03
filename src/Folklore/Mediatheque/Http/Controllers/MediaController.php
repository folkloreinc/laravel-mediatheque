<?php

namespace Folklore\Mediatheque\Http\Controllers;

use Illuminate\Http\Request;
use Folklore\Mediatheque\Contracts\Type\Factory as TypeFactory;

class MediaController extends Controller
{
    protected $typeFactory;

    public function __construct(TypeFactory $typeFactory)
    {
        $this->typeFactory = $typeFactory;
    }

    protected function getTypeFromRequest(Request $request)
    {
        $type = $request->route()->getAction('type');
        return $this->typeFactory->type($type);
    }

    protected function newModelFromRequest(Request $request)
    {
        return $this->getTypeFromRequest($request)->newModel();
    }

    protected function newQueryFromRequest(Request $request)
    {
        return $this->getTypeFromRequest($request)
            ->newQuery()
            ->with('files');
    }

    /**
     * Display a listing of the resource.
     *
     * @return \Illuminate\Http\Response
     */
    public function index(Request $request)
    {
        $query = $this->newQueryFromRequest($request);

        $count = $request->get('count', 15);
        $fields = $request->get('fields', []);
        if (is_string($fields)) {
            $fields = !empty($fields) ? explode(',', $fields) : [];
        }
        $appends = $request->only([
            'count',
            'search',
            'sort',
            'sort_direction',
            'fields'
        ]);

        if ($request->has('search')) {
            $query->search($request->get('search'));
        }

        if ($request->has('sort')) {
            $column = $request->get('sort');
            $direction = $request->get('sort_direction', 'asc');
            $query->orderBy($column, $direction);
        }

        $items = $query->paginate($count)->appends($appends);

        if (sizeof($fields)) {
            $items->makeVisible($fields);
        }

        return $items;
    }

    /**
     * Store a newly created resource in storage.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Http\Response
     */
    public function store(Request $request)
    {
        $input = $request->all();

        $model = $this->newModelFromRequest($request);
        $model->fill($input);
        $model->save();

        return $model;
    }

    /**
     * Display the specified resource.
     *
     * @param  int  $id
     * @return \Illuminate\Http\Response
     */
    public function show($id)
    {
        return $this->newQueryFromRequest($request)->findOrFail($id);
    }

    /**
     * Update the specified resource in storage.
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  int  $id
     * @return \Illuminate\Http\Response
     */
    public function update(Request $request, $id)
    {
        $input = $request->all();

        $item = $this->newQueryFromRequest($request)->findOrFail($id);
        $item->fill($input);
        $item->save();

        return $item;
    }

    /**
     * Remove the specified resource from storage.
     *
     * @param  int  $id
     * @return \Illuminate\Http\Response
     */
    public function destroy(Request $request, $id)
    {
        $item = $this->newQueryFromRequest($request)->findOrFail($id);
        $item->delete();

        return $item;
    }
}
