<?php

namespace JaOcero\FilaChat\Services;

use Filament\Notifications\Notification;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use InvalidArgumentException;
use JaOcero\FilaChat\Events\FilaChatMessageEvent;
use JaOcero\FilaChat\Models\FilaChatAgent;
use JaOcero\FilaChat\Models\FilaChatConversation;
use JaOcero\FilaChat\Models\FilaChatMessage;
use JaOcero\FilaChat\Pages\FilaChat;

class ChatListService
{
    protected $isRoleEnabled;

    protected $isAgent;

    protected $userModelClass;

    protected $agentModelClass;

    protected $userSearchableColumns;

    protected $agentSearchableColumns;

    protected $userChatListDisplayColumn;

    protected $agentChatListDisplayColumn;

    public function __construct()
    {
        $this->isRoleEnabled = config('filachat.enable_roles');
        $this->isAgent = auth()->user()->isAgent();
        $this->userModelClass = config('filachat.user_model');
        $this->agentModelClass = config('filachat.agent_model');
        $this->userChatListDisplayColumn = config('filachat.user_chat_list_display_column');
        $this->agentChatListDisplayColumn = config('filachat.agent_chat_list_display_column');

        // Check if the user model class exists
        if (! class_exists($this->userModelClass)) {
            throw new InvalidArgumentException('User model class ' . $this->userModelClass . ' not found');
        }

        // Check if the agent model class exists
        if (! class_exists($this->agentModelClass)) {
            throw new InvalidArgumentException('Agent model class ' . $this->agentModelClass . ' not found');
        }

        // Validate that all specified columns exist in the user model
        foreach (config('filachat.user_searchable_columns') as $column) {
            $userTable = (new $this->userModelClass)->getTable();
            if (! Schema::hasColumn($userTable, $column)) {
                throw new InvalidArgumentException('Column ' . $column . ' not found in ' . $userTable);
            }
        }
        $this->userSearchableColumns = config('filachat.user_searchable_columns');

        // Validate that all specified columns exist in the agent model
        foreach (config('filachat.agent_searchable_columns') as $column) {
            $agentTable = (new $this->agentModelClass)->getTable();
            if (! Schema::hasColumn($agentTable, $column)) {
                throw new InvalidArgumentException('Column ' . $column . ' not found in ' . $agentTable);
            }
        }
        $this->agentSearchableColumns = config('filachat.agent_searchable_columns');
    }

    public static function make(): self
    {
        return new self;
    }

    public function getSearchResults(string $search): Collection
    {
        $searchTerm = '%' . $search . '%';

        if ($this->isRoleEnabled) {

            $agentIds = $this->agentModelClass::getAllAgentIds();

            if ($this->isAgent) {
                return $this->userModelClass::query()
                    ->whereNotIn('id', $agentIds)
                    ->where(function ($query) use ($searchTerm) {
                        foreach ($this->userSearchableColumns as $column) {
                            $query->orWhere($column, 'like', $searchTerm);
                        }
                    })
                    ->select(
                        DB::raw("CONCAT('user_', id) as user_key"),
                        DB::raw("$this->userChatListDisplayColumn as user_value")
                    )
                    ->get()
                    ->pluck('user_value', 'user_key');
            }

            return $this->agentModelClass::query()
                ->whereIn('id', $agentIds)
                ->where(function ($query) use ($searchTerm) {
                    foreach ($this->agentSearchableColumns as $column) {
                        $query->orWhere($column, 'like', $searchTerm);
                    }
                })
                ->select(
                    DB::raw("CONCAT('agent_', id) as agent_key"),
                    DB::raw("$this->agentChatListDisplayColumn as agent_value")
                )
                ->get()
                ->pluck('agent_value', 'agent_key');
        } else {
            if ($this->userModelClass === $this->agentModelClass) {
                return $this->userModelClass::query()
                    ->whereNot('id', auth()->id())
                    ->where(function ($query) use ($searchTerm) {
                        foreach ($this->userSearchableColumns as $column) {
                            $query->orWhere($column, 'like', $searchTerm);
                        }
                    })
                    ->select(
                        DB::raw("CONCAT('user_', id) as user_key"),
                        DB::raw("$this->userChatListDisplayColumn as user_value")
                    )
                    ->get()
                    ->pluck('user_value', 'user_key');
            }

            $userModel = $this->userModelClass::query()
                ->whereNot('id', auth()->id())
                ->where(function ($query) use ($searchTerm) {
                    foreach ($this->userSearchableColumns as $column) {
                        $query->orWhere($column, 'like', $searchTerm);
                    }
                })
                ->select(
                    DB::raw("CONCAT('user_', id) as user_key"),
                    DB::raw("$this->userChatListDisplayColumn as user_value")
                )
                ->get()
                ->pluck('user_value', 'user_key');

            $agentModel = $this->agentModelClass::query()
                ->whereNot('id', auth()->id())
                ->where(function ($query) use ($searchTerm) {
                    foreach ($this->agentSearchableColumns as $column) {
                        $query->orWhere($column, 'like', $searchTerm);
                    }
                })
                ->select(
                    DB::raw("CONCAT('agent_', id) as agent_key"),
                    DB::raw("$this->agentChatListDisplayColumn as agent_value")
                )
                ->get()
                ->pluck('agent_value', 'agent_key');

            return $userModel->merge($agentModel);
        }
    }

    public function getOptionLabel(string $value): ?string
    {
        if (preg_match('/^user_(\d+)$/', $value, $matches)) {
            $id = (int) $matches[1];

            return $this->userModelClass::find($id)->{$this->userChatListDisplayColumn};
        }

        if (preg_match('/^agent_(\d+)$/', $value, $matches)) {
            $id = (int) $matches[1];

            return $this->agentModelClass::find($id)->{$this->agentChatListDisplayColumn};
        }

        return null;
    }

    public function createConversation(array $data)
    {
        try {
            DB::transaction(function () use ($data) {
                if(!isset($data['receiverable_id'])) {
                    if(
                        !config('filachat.enable_roles', false) ||
                        auth()->user()->isAgent() ||
                        !config('filachat.skip_agent_selection', false)
                    ) {
                        throw new InvalidArgumentException('Receivable ID is required');
                    }

                    $agent = FilaChatAgent::query()->inRandomOrder()->first();

                    $data['receiverable_id'] = "agent_" . $agent->getKey();

                }
                $receiverableId = $data['receiverable_id'];

                if (preg_match('/^user_(\d+)$/', $receiverableId, $matches)) {
                    $receiverableType = $this->userModelClass;
                    $receiverableId = (int) $matches[1];
                }

                if (preg_match('/^agent_(\d+)$/', $receiverableId, $matches)) {
                    $receiverableType = $this->agentModelClass;
                    $receiverableId = (int) $matches[1];
                }

                $foundConversation = null;
                if(!config('filachat.allow_multiple_conversations', false)) {
                    $foundConversation = FilaChatConversation::query()
                        ->where(function ($query) use ($receiverableId, $receiverableType) {
                            $query->where(function ($query) {
                                $query->where('senderable_id', auth()->id())
                                    ->where('senderable_type', auth()->user()::class);
                            })
                                ->orWhere(function ($query) use ($receiverableId, $receiverableType) {
                                    $query->where('senderable_id', $receiverableId)
                                        ->where('senderable_type', $receiverableType);
                                });
                        })
                        ->where(function ($query) use ($receiverableId, $receiverableType) {
                            $query->where(function ($query) use ($receiverableId, $receiverableType) {
                                $query->where('receiverable_id', $receiverableId)
                                    ->where('receiverable_type', $receiverableType);
                            })
                                ->orWhere(function ($query) {
                                    $query->where('receiverable_id', auth()->id())
                                        ->where('receiverable_type', auth()->user()::class);
                                });
                        })
                        ->first();
                }

                if (! $foundConversation) {
                    $conversation = FilaChatConversation::query()->create([
                        'senderable_id' => auth()->id(),
                        'senderable_type' => auth()->user()::class,
                        'receiverable_id' => $receiverableId,
                        'receiverable_type' => $receiverableType,
                    ]);
                } else {
                    $conversation = $foundConversation;
                }

                $message = FilaChatMessage::query()->create([
                    'filachat_conversation_id' => $conversation->id,
                    'senderable_id' => auth()->id(),
                    'senderable_type' => auth()->user()::class,
                    'receiverable_id' => $receiverableId,
                    'receiverable_type' => $receiverableType,
                    'message' => $data['message'],
                ]);

                $conversation->updated_at = now();

                $conversation->save();

                broadcast(new FilaChatMessageEvent(
                    $conversation->id,
                    $message->id,
                    $receiverableId,
                    auth()->id(),
                ));

                return redirect(FilaChat::getUrl(tenant: filament()->getTenant()) . '/' . $conversation->id);
            });
        } catch (\Exception $exception) {
            Notification::make()
                ->title('Something went wrong')
                ->body($exception->getMessage())
                ->danger()
                ->persistent()
                ->send();
        }
    }
}
