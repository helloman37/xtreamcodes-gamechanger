    </div>
  </div>
</div>

<!-- Player Modal -->
<div class="modal" id="portalModal">
  <div class="box">
    <div class="boxhead">
      <div>
        <div class="title js-modal-title">Now Playing</div>
        <div class="js-modal-badges" style="margin-top:6px; display:flex; gap:8px; flex-wrap:wrap;"></div>
      </div>
      <button class="close js-modal-close" type="button">Close</button>
    </div>
    <div class="body">
      <!-- jPlayer (with HLS.js bridge) -->
      <div id="jp_container" class="jp-video jp-video-360p" role="application" aria-label="media player">
        <div class="jp-type-single">
          <div id="jquery_jplayer" class="jp-jplayer"></div>

          <div class="jp-gui">
            <div class="jp-video-play">
              <button class="jp-video-play-icon" type="button" tabindex="0">play</button>
            </div>
            <div class="jp-interface">
              <div class="jp-progress">
                <div class="jp-seek-bar">
                  <div class="jp-play-bar"></div>
                </div>
              </div>
              <div class="jp-current-time" role="timer" aria-label="time">&nbsp;</div>
              <div class="jp-duration" role="timer" aria-label="duration">&nbsp;</div>

              <div class="jp-controls-holder">
                <div class="jp-controls">
                  <button class="jp-play" type="button" tabindex="0">play</button>
                  <button class="jp-stop" type="button" tabindex="0">stop</button>
                </div>
                <div class="jp-volume-controls">
                  <button class="jp-mute" type="button" tabindex="0">mute</button>
                  <button class="jp-volume-max" type="button" tabindex="0">max</button>
                  <div class="jp-volume-bar"><div class="jp-volume-bar-value"></div></div>
                </div>
                <div class="jp-toggles">
                  <button class="jp-full-screen" type="button" tabindex="0">full</button>
                </div>
              </div>

              <div class="jp-details">
                <div class="jp-title" aria-label="title">&nbsp;</div>
              </div>
            </div>
          </div>

          <div class="jp-no-solution">
            <span>Update Required</span>
            Your browser does not support playback.
          </div>
        </div>
      </div>
      <div class="desc js-modal-desc" style="margin-top:10px;"></div>
    </div>
  </div>
</div>

<script src="/portal/assets/portal.js"></script>
</body>
</html>
