'use strict';
(() => {
  const $ = id => document.getElementById(id);
  const csrf = document.querySelector('meta[name="dashboard-token"]').content;
  const boxes = new Map(), cards = new Map(), images = new Map(), messages = new Map(), pendingReboots = new Map();
  const busy = new Set(), imageBusy = new Set();
  let registryGeneration = 0, refreshQueued = false, ordering = false;
  let drag = null, dragFrame = null;
  let polling = false, timer = null, editor = null, settingsBox = null, confirmation = null, previewId = null;
  let editorSaving = false, settingsSaving = false, confirmationSaving = false;
  let pageActive = true, cecTimer = null, cecRunning = false;
  const checkRequests = new Set();
  const time = value => { const date = new Date(value); return Number.isNaN(+date) ? '—' : date.toLocaleTimeString('fr-CH', {hour:'2-digit', minute:'2-digit', second:'2-digit'}); };
  const formatTime = value => /^\d{4}$/.test(value || '') ? `${value.slice(0,2)}:${value.slice(2)}` : '—';
  function notice(id, text = '') { $(id).textContent = text; $(id).hidden = !text; }
  function announce(text) { $('announcement').textContent = text; }
  async function request(action, {method='GET', body, timeout=12000, id, background=false} = {}) {
    const controller = new AbortController(), deadline = setTimeout(() => controller.abort(), timeout);
    if(background)checkRequests.add(controller);
    try {
      const response = await fetch(`api.php?action=${encodeURIComponent(action)}${id ? '&id='+encodeURIComponent(id) : ''}`, {
        method, headers:{'X-Dashboard-Token':csrf, ...(body ? {'Content-Type':'application/json'} : {})},
        body:body ? JSON.stringify(body) : undefined, cache:'no-store', signal:controller.signal
      });
      if (action === 'image' && response.ok) {
        return {blob: await response.blob(), bar: response.headers.get('X-Orange-Bar')};
      }
      const data = await response.json().catch(() => ({}));
      if (response.status === 401 && data.code === 'auth_required') {
        pageActive=false;stopChecks();window.location.replace('/login.php');
        throw new Error('Connexion requise.');
      }
      if (!response.ok) { const error = new Error(data.error || 'Le tableau de bord ne répond pas correctement.'); error.status=response.status; throw error; }
      return data;
    } catch(error) {
      if (error.name === 'AbortError') throw new Error('La réponse n’est pas arrivée à temps. Vérifier l’état avant de réessayer.');
      if (error instanceof TypeError) throw new Error('Le serveur local est inaccessible. Vérifier qu’il est démarré.');
      throw error;
    } finally { clearTimeout(deadline);checkRequests.delete(controller); }
  }
  function message(id, text, tone='') { messages.set(id,{text,tone}); const box=boxes.get(id); if(box) render(box); }
  function checksEnabled() { return pageActive && !document.hidden && $('auto-refresh').checked; }
  function schedulePoll() { clearTimeout(timer); if (checksEnabled()) timer=setTimeout(refresh,15000); }
  function stopChecks() {
    clearTimeout(timer);clearTimeout(cecTimer);
    for(const controller of checkRequests)controller.abort();
  }
  async function refreshCecAutomatically() {
    clearTimeout(cecTimer);
    if(!checksEnabled() || cecRunning)return;
    cecRunning=true;const started=Date.now();
    try {
      await request('cec-auto',{method:'POST',body:{},timeout:65000,background:true});
      if(checksEnabled())refresh();
    } catch(error) { if(checksEnabled())notice('global-error',error.message); }
    finally {
      cecRunning=false;
      if(checksEnabled())cecTimer=setTimeout(refreshCecAutomatically,Math.max(1000,60000-(Date.now()-started)));
    }
  }
  function updateSummary() {
    $('empty').hidden=boxes.size>0;
    updateMoveButtons();
  }
  function updateMoveButtons() {
    const visible=[...boxes.keys()].filter(id=>!cards.get(id).hidden);
    for(const id of boxes.keys()) {
      const index=visible.indexOf(id), card=cards.get(id);
      card.querySelector('[data-action="move-before"]').disabled=ordering || index<=0;
      card.querySelector('[data-action="move-after"]').disabled=ordering || index<0 || index===visible.length-1;
      card.querySelector('.drag-handle').disabled=ordering || visible.length<2 || !!drag && drag.id!==id;
    }
  }
  async function moveBox(id, step) {
    if(ordering || drag)return;
    const expected=[...boxes.keys()], visible=expected.filter(key=>!cards.get(key).hidden);
    const index=visible.indexOf(id), neighbor=visible[index+step];
    if(index<0 || !neighbor)return;
    const order=[...expected], from=order.indexOf(id), to=order.indexOf(neighbor);
    [order[from],order[to]]=[order[to],order[from]];
    await saveOrder(id, order, expected);
  }
  async function saveOrder(id, order, expected) {
    if(ordering || order.every((key,index)=>key===expected[index]))return;
    ordering=true;registryGeneration++;updateMoveButtons();
    try {
      const data=await request('reorder',{method:'POST',body:{order,expected_order:expected}});
      registryGeneration++;
      reconcile(data.boxes.map(row=>({...boxes.get(row.id),...row})));
      notice('global-error');announce('Ordre des box enregistré.');
    } catch(error) {notice('global-error',error.message);}
    finally {ordering=false;updateMoveButtons();cards.get(id)?.querySelector('.drag-handle').focus({preventScroll:true});refresh();}
  }
  function trackDrag() {
    if(!drag?.active)return;
    const {x,y,id}=drag;
    const ghost=$('drag-preview');
    ghost.style.left=Math.max(8,Math.min(x+16,innerWidth-ghost.offsetWidth-8))+'px';
    ghost.style.top=Math.max(8,Math.min(y+16,innerHeight-ghost.offsetHeight-8))+'px';
    const target=document.elementFromPoint(x,y)?.closest('.screen-card');
    const targetId=target && !target.hidden && target.dataset.id!==id ? target.dataset.id : null;
    if(targetId!==drag.target) {
      cards.get(drag.target)?.classList.remove('drop-target');
      drag.target=targetId;
      if(targetId){target.classList.add('drop-target');announce(`Déplacer ${boxes.get(id).name} à la place de ${boxes.get(targetId).name}.`);}
    }
  }
  function scrollDrag() {
    if(!drag?.active)return;
    const margin=64, speed=drag.y<margin ? -Math.ceil((margin-drag.y)/5) : drag.y>innerHeight-margin ? Math.ceil((drag.y-innerHeight+margin)/5) : 0;
    if(speed && drag.x>=0 && drag.x<=innerWidth){window.scrollBy(0,speed);trackDrag();}
    dragFrame=requestAnimationFrame(scrollDrag);
  }
  function finishDrag(commit) {
    if(!drag)return;
    const previous=drag;drag=null;
    cancelAnimationFrame(dragFrame);dragFrame=null;
    cards.get(previous.id)?.classList.remove('drag-source');
    cards.get(previous.target)?.classList.remove('drop-target');
    document.body.classList.remove('dragging');$('drag-preview').hidden=true;
    if(previous.handle.hasPointerCapture(previous.pointerId))previous.handle.releasePointerCapture(previous.pointerId);
    updateMoveButtons();
    if(commit && previous.active && previous.target) {
      const expected=previous.expected, visible=expected.filter(id=>!cards.get(id)?.hidden);
      const from=visible.indexOf(previous.id), to=visible.indexOf(previous.target);
      if(from>=0 && to>=0) {
        visible.splice(from,1);visible.splice(to,0,previous.id);
        let index=0;
        const order=expected.map(id=>cards.get(id).hidden?id:visible[index++]);
        saveOrder(previous.id,order,expected);return;
      }
    }
    if(previous.active)announce('Déplacement annulé.');
    if(refreshQueued)refresh();
  }
  function setupDrag(card, id) {
    const handle=card.querySelector('.drag-handle');
    handle.addEventListener('pointerdown',event=>{
      if(event.button!==0 || !event.isPrimary || ordering || drag || handle.disabled)return;
      event.preventDefault();handle.focus({preventScroll:true});
      for(const item of cards.values())item.querySelector('details').open=false;
      drag={id,handle,pointerId:event.pointerId,startX:event.clientX,startY:event.clientY,x:event.clientX,y:event.clientY,active:false,target:null,expected:[...boxes.keys()]};
      handle.setPointerCapture(event.pointerId);updateMoveButtons();
    });
    handle.addEventListener('pointermove',event=>{
      if(!drag || drag.pointerId!==event.pointerId)return;
      drag.x=event.clientX;drag.y=event.clientY;
      if(!drag.active && Math.hypot(drag.x-drag.startX,drag.y-drag.startY)>6) {
        drag.active=true;card.classList.add('drag-source');document.body.classList.add('dragging');
        $('drag-preview').textContent=boxes.get(id).name;$('drag-preview').hidden=false;
        dragFrame=requestAnimationFrame(scrollDrag);
      }
      trackDrag();
    });
    handle.addEventListener('pointerup',event=>{if(drag?.pointerId===event.pointerId){trackDrag();finishDrag(true);}});
    for(const name of ['pointercancel','lostpointercapture'])handle.addEventListener(name,()=>finishDrag(false));
    handle.addEventListener('keydown',event=>{
      if(['ArrowLeft','ArrowUp','ArrowRight','ArrowDown'].includes(event.key)) {
        event.preventDefault();moveBox(id,['ArrowLeft','ArrowUp'].includes(event.key)?-1:1);
      }
    });
  }
  document.addEventListener('keydown',event=>{if(event.key==='Escape' && drag){event.preventDefault();finishDrag(false);}});
  window.addEventListener('blur',()=>finishDrag(false));
  function showCaptureStatus(card, box, status) {
    card.dataset.captureStatus = status;
    const hasImage = images.has(box.id);
    const placeholder = card.querySelector('.preview-placeholder');
    const unavailable = status === 'unavailable';
    placeholder.hidden = hasImage;
    placeholder.textContent = unavailable ? 'Capture indisponible' : 'Chargement de la capture…';
    const notice = card.querySelector('.capture-notice');
    let text = '';
    if (status === 'alarm') text = 'Alerte : barre orange absente sur deux captures successives.';
    if (status === 'unverified') text = 'Fraîcheur de la capture non confirmée.';
    if (unavailable && hasImage) text = 'Capture indisponible';
    if (notice.textContent !== text) notice.textContent = text;
    notice.hidden = !text;
    notice.dataset.alarm = String(status === 'alarm');
  }
  function render(box) {
    let card=cards.get(box.id);
    if (!card) {
      card=$('screen-template').content.firstElementChild.cloneNode(true); card.dataset.id=box.id; cards.set(box.id,card); $('screens').append(card);
      setupDrag(card,box.id);
      card.addEventListener('click', event => {
        const button=event.target.closest('button'); if(!button || button.disabled) return;
        if(button.dataset.mode) { execute(box.id,'mode',{mode:button.dataset.mode}); return; }
        const action=button.dataset.action; if(!action)return;
        card.querySelector('details').open=false;
        if(action==='edit') openEditor(box.id);
        else if(action==='move-before') moveBox(box.id,-1);
        else if(action==='move-after') moveBox(box.id,1);
        else if(action==='settings') openSettings(box.id);
        else if(action==='preview') openPreview(box.id);
        else if(['remove','restart-box','restart-display'].includes(action)) openConfirmation(box.id,action);
        else execute(box.id,action);
      });
      card.querySelector('summary').addEventListener('click',()=>{
        for(const other of cards.values()) if(other!==card)other.querySelector('details').open=false;
      });
    }
    const active=busy.has(box.id), state=box.state, online=box.online===true;
    card.classList.toggle('busy',active); card.setAttribute('aria-busy',String(active));
    card.querySelector('h2').textContent=box.name; card.querySelector('h2').title=box.name;
    card.querySelector('.drag-handle').setAttribute('aria-label',`Déplacer ${box.name}`);
    card.querySelector('summary').setAttribute('aria-label',`Actions de ${box.name}`);
    const connection=card.querySelector('.connection');
    connection.className='connection '+(online?'online':box.online===false?'offline':'');
    connection.textContent=pendingReboots.has(box.id)?'Redémarrage…':online?'Joignable':box.online===false?({key:'Clé refusée',api:'API à vérifier',offline:'Hors ligne'}[box.error_kind]||'À vérifier'):'Vérification…';
    const capture=state?.capture;
    const tvBrand=state?.brand?.trim(), tvName=state?.tv_name?.trim();
    const nameIncludesBrand=tvBrand && tvName && (tvName.toLowerCase()===tvBrand.toLowerCase() || tvName.toLowerCase().startsWith(tvBrand.toLowerCase()+' '));
    card.querySelector('.tv-brand').textContent=tvBrand && tvName && !nameIncludesBrand ? `${tvBrand} · ${tvName}` : tvName || tvBrand || 'Télévision';
    const tvStates={on:'Allumée',off:'En veille',to_on:'Allumage en cours',to_off:'Mise en veille en cours',unknown:'État non communiqué'};
    const tvStale=!!state && (!online || state.tv_age_seconds>120);
    const tvStatus=card.querySelector('.tv-status');
    tvStatus.textContent=(state ? tvStates[state.tv_status] || tvStates.unknown : tvStates.unknown) + (tvStale ? ' · à revérifier' : '');
    tvStatus.classList.toggle('stale',tvStale);
    const config=state?.config;
    card.querySelector('.schedule').textContent=config ? (config.MODE==='auto' ? `${formatTime(config.ON_TIME)} – ${formatTime(config.OFF_TIME)} · ${config.WEEKEND_ENABLED==='yes'?'Tous les jours':'Lun. – ven.'}` : config.MODE==='force_on'?'Allumage forcé · horaires conservés':'Veille forcée · horaires conservés') : 'Réglages indisponibles';
    for(const button of card.querySelectorAll('[data-mode]')) { button.disabled=!online || active || pendingReboots.has(box.id); button.setAttribute('aria-pressed',String(config?.MODE===button.dataset.mode)); }
    for(const button of card.querySelectorAll('[data-action]')) {
      if(button.dataset.action.startsWith('move-'))continue;
      const local=['edit','remove','preview'].includes(button.dataset.action);
      button.disabled=active || (!local && (!online || pendingReboots.has(box.id)));
      button.title=state?.api_generation==='legacy' && button.dataset.action==='restart-display'?'Cette ancienne API nécessite un redémarrage complet de la box, après confirmation.':'';
      if(button.dataset.action==='preview') button.disabled=!images.has(box.id);
    }
    const msg=messages.get(box.id), el=card.querySelector('.box-message');
    el.textContent=msg?.text || (box.online===false && box.error_kind!=='offline' ? box.error || 'Box indisponible.' : '');
    el.className='box-message '+(msg?.tone || (box.online===false?'error':'')); el.hidden=!el.textContent;
    const preview=card.querySelector('.preview'); preview.setAttribute('aria-label',`Agrandir l’écran ${box.name}`);
    const unavailable = box.online === false || capture?.available === false;
    showCaptureStatus(card, box, unavailable ? 'unavailable' : card.dataset.captureStatus || 'loading');
  }
  async function loadImages() {
    const queue=[...boxes.values()].filter(box=>box.online && box.state?.capture.available && !busy.has(box.id) && !imageBusy.has(box.id));
    async function worker() {
      while(queue.length) {
        const box=queue.shift(); imageBusy.add(box.id);
        try {
          if(!pageActive || document.hidden)break;
          const {blob, bar}=await request('image',{id:box.id,timeout:15000,background:true});
          if(!boxes.has(box.id) || boxes.get(box.id).revision!==box.revision)continue;
          const url=URL.createObjectURL(blob), check=new Image();check.src=url;
          try {await check.decode();} catch(error) {URL.revokeObjectURL(url);throw error;}
          if(!boxes.has(box.id) || boxes.get(box.id).revision!==box.revision){URL.revokeObjectURL(url);continue;}
          const previous=images.get(box.id);
          images.set(box.id,url); const card=cards.get(box.id), img=card.querySelector('.preview img');
          card.querySelector('.preview').removeAttribute('title');
          img.src=url; img.hidden=false; card.querySelector('.preview-placeholder').hidden=true; card.querySelector('.preview').disabled=false;
          showCaptureStatus(card, box, bar || 'unverified');
          if(previewId===box.id) $('large-preview').src=url;
          if(previous)URL.revokeObjectURL(previous);
        } catch(_) {
          const card=cards.get(box.id);
          if(card && pageActive && !document.hidden && boxes.get(box.id)?.revision===box.revision) {
            card.querySelector('.preview').title=images.has(box.id)?'Dernière image disponible : actualisation indisponible.':'Image indisponible';
            showCaptureStatus(card, box, 'unavailable');
          }
        } finally { imageBusy.delete(box.id); }
      }
    }
    await Promise.all([worker(),worker(),worker()]);
  }
  function reconcile(rows) {
    const ids=new Set(rows.map(b=>b.id));
    for(const id of boxes.keys())if(!ids.has(id)) {
      boxes.delete(id); cards.get(id)?.remove(); cards.delete(id); messages.delete(id); pendingReboots.delete(id);
      if(images.has(id)){URL.revokeObjectURL(images.get(id));images.delete(id);}
      if(previewId===id){$('preview-dialog').close();previewId=null;}
    }
    for(const incoming of rows) {
      const previous=boxes.get(incoming.id);
      const box={...incoming};
      if(previous?.state && !incoming.state)box.state=previous.state;
      if(busy.has(incoming.id) && previous) { box.online=previous.online; box.state=previous.state; }
      boxes.delete(box.id);boxes.set(box.id,box);
      const reboot=pendingReboots.get(box.id);
      if(reboot) {
        if(box.online && box.state?.boot_id && reboot.bootId && box.state.boot_id!==reboot.bootId) {pendingReboots.delete(box.id);messages.set(box.id,{text:'La box a redémarré et répond de nouveau.',tone:'success'});}
        else if(Date.now()>reboot.until){pendingReboots.delete(box.id);messages.set(box.id,{text:'Le retour de la box n’a pas été confirmé. Vérifier sa connexion avant de redémarrer à nouveau.',tone:'warning'});}
      }
      render(box);
    }
    let next=$('screens').firstElementChild;
    for(const row of rows) {
      const card=cards.get(row.id);
      if(card!==next)$('screens').insertBefore(card,next);
      next=card.nextElementSibling;
    }
    updateSummary();
  }
  async function refresh() {
    if(!pageActive || document.hidden)return;
    if(polling || ordering || drag){refreshQueued=true;return;}
    const generation=registryGeneration;
    polling=true; clearTimeout(timer); $('refresh').disabled=true;
    try {
      const data=await request('status',{background:true}); if(generation!==registryGeneration || drag){refreshQueued=true;return;} reconcile(data.boxes); notice('global-error');
      $('last-refresh').textContent=`Dernière vérification à ${time(new Date())}`;
      await loadImages();
    } catch(error) {if(pageActive && !document.hidden)notice('global-error',error.message);}
    finally {polling=false;$('refresh').disabled=false;$('loading').hidden=true;if(refreshQueued && !drag && !ordering){refreshQueued=false;setTimeout(refresh,0);}else schedulePoll();}
  }
  function openEditor(id=null) {
    editor=id ? boxes.get(id) : null; $('box-form').reset(); notice('box-error');
    $('box-title').textContent=editor?'Modifier la box':'Ajouter une box'; $('box-submit').textContent=editor?'Enregistrer':'Ajouter la box';
    $('box-name').value=editor?.name||''; $('box-address').value=editor?.address||''; $('box-key').value=''; $('box-key').required=!editor;
    $('box-key').placeholder=editor?'Laisser vide pour conserver la clé':'';
    $('key-help').textContent=editor?'La clé actuelle est enregistrée. Renseigner ce champ uniquement pour la remplacer.':'La clé reste enregistrée sur cet ordinateur.';
    $('box-dialog').showModal(); $('box-name').focus();
  }
  $('box-form').addEventListener('submit',async event=>{
    event.preventDefault();if(editorSaving)return; editorSaving=true; const original=$('box-submit').textContent; $('box-submit').disabled=true;$('box-submit').textContent='Enregistrement…';notice('box-error');
    try {
      const body={name:$('box-name').value,address:$('box-address').value,key:$('box-key').value};
      if(editor)Object.assign(body,{id:editor.id,revision:editor.revision});
      const data=await request('save',{method:'POST',body}); registryGeneration++;
      const old=boxes.get(data.box.id); if(old?.address!==data.box.address || body.key) {
        if(images.has(data.box.id)){URL.revokeObjectURL(images.get(data.box.id));images.delete(data.box.id);}
        cards.get(data.box.id)?.remove();cards.delete(data.box.id);messages.delete(data.box.id);
      }
      boxes.set(data.box.id,data.box);render(data.box);updateSummary();$('box-key').value='';$('box-dialog').close(); announce(`${data.box.name} enregistrée.`);
      await refresh();
    }catch(error){notice('box-error',error.message);}finally{editorSaving=false;$('box-submit').disabled=false;$('box-submit').textContent=original;}
  });
  function openSettings(id) {
    const box=boxes.get(id); if(!box?.online || !box.state)return;
    const c=box.state.config;
    settingsBox={id,revision:box.state.revision,hdmiLock:c.HDMI_LOCK_ENABLED};
    $('settings-box-name').textContent=box.name; $('settings-mode').value=c.MODE||'auto';
    $('settings-on').value=formatTime(c.ON_TIME);$('settings-off').value=formatTime(c.OFF_TIME);
    $('settings-weekend').checked=c.WEEKEND_ENABLED==='yes';
    notice('settings-error');setModeFields();$('settings-dialog').showModal();
  }
  function setModeFields() {const forced=$('settings-mode').value!=='auto'; for(const id of ['settings-on','settings-off','settings-weekend'])$(id).disabled=forced || settingsSaving;}
  $('settings-mode').addEventListener('change',setModeFields);
  $('settings-form').addEventListener('submit',async event=>{
    event.preventDefault();if(settingsSaving || !settingsBox)return;
    const settings={MODE:$('settings-mode').value,ON_TIME:$('settings-on').value.replace(':',''),OFF_TIME:$('settings-off').value.replace(':',''),WEEKEND_ENABLED:$('settings-weekend').checked?'yes':'no',HDMI_LOCK_ENABLED:settingsBox.hdmiLock ?? undefined,revision:settingsBox.revision};
    if(settings.ON_TIME===settings.OFF_TIME){notice('settings-error','Les deux heures doivent être différentes.');return;}
    settingsSaving=true;setModeFields();$('settings-mode').disabled=true;$('settings-submit').disabled=true;$('settings-submit').textContent='Application…';notice('settings-error');
    try {await execute(settingsBox.id,'settings',{settings},true);$('settings-dialog').close();}
    catch(error){notice('settings-error',error.message);}
    finally {settingsSaving=false;$('settings-mode').disabled=false;$('settings-submit').disabled=false;$('settings-submit').textContent='Enregistrer et appliquer';setModeFields();}
  });
  function openConfirmation(id,action) {
    const box=boxes.get(id);if(!box)return;
    const fallback=action==='restart-display' && box.state?.api_generation==='legacy';
    const confirmedAction=fallback?'restart-box':action;
    confirmation={id,action:confirmedAction,revision:box.revision};
    const remove=action==='remove', whole=confirmedAction==='restart-box';
    $('confirm-title').textContent=remove?'Retirer cette box ?':whole?'Redémarrer la box ?':'Redémarrer l’affichage ?';
    $('confirm-description').textContent=fallback
      ? `L’ancienne API de ${box.name} ne permet pas de relancer uniquement l’affichage. Toute la box sera redémarrée et sera momentanément inaccessible. L’affichage sera interrompu pendant le redémarrage.`
      : remove?`${box.name} sera retirée de ce tableau de bord et sa clé enregistrée sera supprimée ici. La box continuera à fonctionner.`:whole?`${box.name} sera momentanément inaccessible. Sa télévision et son affichage peuvent être interrompus pendant le redémarrage.`:`L’affichage de ${box.name} sera brièvement interrompu, puis relancé.`;
    $('confirm-action').textContent=remove?'Retirer la box':whole?'Redémarrer la box':'Redémarrer l’affichage';notice('confirm-error');$('confirm-dialog').showModal();
  }
  $('confirm-action').addEventListener('click',async()=>{
    if(confirmationSaving || !confirmation)return;confirmationSaving=true;$('confirm-action').disabled=true;
    const {id,action,revision}=confirmation;
    try {
      if(action==='remove') {
        await request('remove',{method:'POST',body:{id,revision,confirm:true}});
        registryGeneration++;reconcile([...boxes.values()].filter(b=>b.id!==id));announce('Box retirée du tableau de bord.');
      } else await execute(id,action,{confirm:true},true);
      $('confirm-dialog').close();
    }catch(error){notice('confirm-error',error.message);}finally{confirmationSaving=false;$('confirm-action').disabled=false;}
  });
  async function execute(id,command,extra={},propagate=false) {
    const box=boxes.get(id);if(!box || busy.has(id))return;
    busy.add(id);message(id,command==='cec-refresh'?'Vérification de la TV…':'Commande en cours…');
    try {
      const data=await request('command',{method:'POST',body:{id,box_revision:box.revision,command,...extra},timeout:70000});
      if(command==='restart-box' && data.accepted && (data.boot_id||box.state?.boot_id))pendingReboots.set(id,{bootId:data.boot_id||box.state.boot_id,until:Date.now()+180000});
      message(id,data.message,data.applied===false?'warning':'success');announce(`${box.name} : ${data.message}`);
      return data;
    }catch(error){message(id,error.message,'error');if(propagate)throw error;}
    finally{busy.delete(id);if(boxes.has(id))render(boxes.get(id));refresh();}
  }
  function openPreview(id) {const box=boxes.get(id),src=images.get(id);if(!box||!src)return;previewId=id;$('preview-title').textContent=box.name;$('preview-description').textContent='Dernière capture disponible';$('large-preview').src=src;$('large-preview').alt=`Capture de ${box.name}`;$('preview-dialog').showModal();}
  for(const dialog of document.querySelectorAll('dialog')) {
    dialog.querySelectorAll('.close').forEach(button=>button.addEventListener('click',()=>{if(!editorSaving && !settingsSaving && !confirmationSaving)dialog.close();}));
    dialog.addEventListener('cancel',event=>{if(editorSaving || settingsSaving || confirmationSaving)event.preventDefault();});
  }
  $('box-dialog').addEventListener('close',()=>{$('box-key').value='';});
  $('preview-dialog').addEventListener('close',()=>{previewId=null;});
  document.addEventListener('click',event=>{if(!event.target.closest('.more'))for(const card of cards.values())card.querySelector('details').open=false;});
  $('add-box').addEventListener('click',()=>openEditor());$('first-box').addEventListener('click',()=>openEditor());
  function resumeChecks() {if(checksEnabled()){refresh();refreshCecAutomatically();}}
  $('refresh').addEventListener('click',refresh);
  $('auto-refresh').addEventListener('change',()=>{stopChecks();resumeChecks();});
  document.addEventListener('visibilitychange',()=>{stopChecks();resumeChecks();});
  window.addEventListener('pagehide',()=>{pageActive=false;stopChecks();});
  window.addEventListener('pageshow',event=>{pageActive=true;if(event.persisted)resumeChecks();});
  (async()=>{try{const data=await request('list');reconcile(data.boxes);$('loading').hidden=true;await refresh();refreshCecAutomatically();}catch(error){$('loading').hidden=true;notice('global-error',error.message);schedulePoll();}})();
})();
