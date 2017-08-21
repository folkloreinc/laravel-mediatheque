<?php

namespace Folklore\Mediatheque\Http\Controllers;

use Illuminate\Http\Request;

abstract class ResourceController extends Controller
{
    abstract protected function getModel();

    protected function getQuery()
    {
        $model = $this->getModel();
        return $model->newQuery()
            ->with('files');
    }

    /**
     * Display a listing of the resource.
     *
     * @return \Illuminate\Http\Response
     */
    public function index(Request $request)
    {
        $query = $this->getQuery();

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

        $items = $query->paginate($count)
            ->appends($appends);

        if (sizeof($fields)) {
            foreach ($items as $item) {
                $item->setVisible($fields);
            }
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

        $model = $this->getModel();
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
        return $this->getQuery()
            ->findOrFail($id);
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

        $item = $this->getQuery()
            ->findOrFail($id);

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
        $item = $this->getQuery()
            ->findOrFail($id);

        $item->delete();

        return $item;
    }
}
