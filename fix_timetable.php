<?php
$filePath = 'd:/project/laravel/school-management/frontend/src/components/TimetableBuilder.tsx';
$lines = file($filePath);

$newContent = <<<EOD
                                      <td 
                                        key={cls.id} 
                                        onClick={() => !entry && handleSlotClick(dayIdx, period, cls.id)}
                                        className={`p-4 border-r border-slate-100 min-w-[200px] cursor-pointer hover:bg-slate-50/50 relative group \${isConsecutive ? 'border-t-0' : ''}`}
                                      >
                                        {entry ? (
                                          <div className={`p-4 rounded-2xl text-white shadow-lg transition-all \${getColorForSubject(entry.subject?.name)} \${isConsecutive ? 'bg-pattern-lines scale-[1.02] -my-1 border-y-2 border-white/20' : ''} relative`}>
                                            <div className="absolute top-2 right-2 flex gap-1 opacity-0 group-hover:opacity-100 transition-opacity z-20">
                                               <button onClick={(e) => { e.stopPropagation(); setSubModalTarget(entry) }} className="p-1 bg-white/20 rounded hover:bg-white/40"><RefreshIcon size={10}/></button>
                                               <button onClick={(e) => { e.stopPropagation(); handleDeleteEntry(entry.id) }} className="p-1 bg-white/20 rounded hover:bg-rose-500/40"><Trash2 size={10}/></button>
                                            </div>
                                            <div className="text-[12px] font-black uppercase mb-1">{entry.subject?.name}</div>
                                            <div className="text-[10px] opacity-80">{entry.teacher?.name} | {entry.classroom?.name}</div>
                                          </div>
                                        ) : (
                                          <div className="flex justify-center opacity-0 group-hover:opacity-100 py-2">
                                            <Plus size={14} className="text-indigo-300" />
                                          </div>
                                        )}
                                      </td>
EOD;

// Replace lines 450 to 461 (0-indexed 449 to 460)
array_splice($lines, 449, 12, [$newContent . "\n"]);

file_put_contents($filePath, implode("", $lines));
echo "File updated successfully.";
?>
