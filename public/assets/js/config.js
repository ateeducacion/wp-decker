!(function () {
  var t = sessionStorage.getItem("__CONFIG__"),
    e = document.getElementsByTagName("html")[0],
    i = {
      theme: "light",
      nav: "vertical",
      layout: { mode: "fluid", position: "fixed" },
      topbar: { color: "light" },
      menu: { color: "dark" },
      sidenav: { size: "default", user: !1 },
    },
    o =
      ((this.html = document.getElementsByTagName("html")[0]),
      (config = Object.assign(JSON.parse(JSON.stringify(i)), {})),
      this.html.getAttribute("data-bs-theme")),
    o =
      ((config.theme = null !== o ? o : i.theme),
      this.html.getAttribute("data-layout")),
    o =
      ((config.nav =
        null !== o ? ("topnav" === o ? "horizontal" : "vertical") : i.nav),
      this.html.getAttribute("data-layout-mode")),
    o =
      ((config.layout.mode = null !== o ? o : i.layout.mode),
      this.html.getAttribute("data-layout-position")),
    o =
      ((config.layout.position = null !== o ? o : i.layout.position),
      this.html.getAttribute("data-topbar-color")),
    o =
      ((config.topbar.color = null != o ? o : i.topbar.color),
      this.html.getAttribute("data-sidenav-size")),
    o =
      ((config.sidenav.size = null !== o ? o : i.sidenav.size),
      this.html.getAttribute("data-sidenav-user")),
    o =
      ((config.sidenav.user = null !== o || i.sidenav.user),
      this.html.getAttribute("data-menu-color"));
  if (
    ((config.menu.color = null !== o ? o : i.menu.color),
    (window.defaultConfig = JSON.parse(JSON.stringify(config))),
    null !== t && (config = JSON.parse(t)),
    (window.config = config),
    "topnav" === e.getAttribute("data-layout")
      ? (config.nav = "horizontal")
      : (config.nav = "vertical"),
    config &&
      (e.setAttribute("data-bs-theme", config.theme),
      e.setAttribute("data-layout-mode", config.layout.mode),
      e.setAttribute("data-menu-color", config.menu.color),
      e.setAttribute("data-topbar-color", config.topbar.color),
      e.setAttribute("data-layout-position", config.layout.position),
      "vertical" == config.nav))
  ) {
    let t = config.sidenav.size;
    window.innerWidth <= 767
      ? (t = "full")
      : 767 <= window.innerWidth &&
        window.innerWidth <= 1140 &&
        "full" !== self.config.sidenav.size &&
        "fullscreen" !== self.config.sidenav.size &&
        (t = "condensed"),
      e.setAttribute("data-sidenav-size", t),
      config.sidenav.user && "true" === config.sidenav.user.toString()
        ? e.setAttribute("data-sidenav-user", !0)
        : e.removeAttribute("data-sidenav-user");
  }
})();


document.addEventListener('mousedown', function(event) {
  // Detects if the click was on a link that has data-bs-toggle="modal"
  const link = event.target.closest('a[data-bs-toggle="modal"]');
  if (link) {
    // If Ctrl (Windows) or Cmd (Mac) are pressed, allow the link to open in a new tab
    if (event.ctrlKey || event.metaKey) {
      link.removeAttribute('data-bs-toggle'); // Temporarily remove the attribute to prevent the modal

      setTimeout(() => {
        // Restore the attribute after the click completes so the modal works for future clicks
        link.setAttribute('data-bs-toggle', 'modal');
      }, 100);

      event.preventDefault();
      return; // Allow the default behavior to open in a new tab
    }
  }
});


let altPressed = false;

document.addEventListener('keydown', function(event) {
  if (event.key === 'Alt' || event.code === 'AltLeft' || event.code === 'AltRight') {
    if (!altPressed) {
      altPressed = true;

      // Select all elements with the classes label-to-show and label-to-hide
      const showLabels = document.querySelectorAll('.label-to-show');
      const hideLabels = document.querySelectorAll('.label-to-hide');

      // Hide all elements with label-to-show
      showLabels.forEach(label => {
        label.style.display = 'block';
      });

      // Show all elements with label-to-hide
      hideLabels.forEach(label => {
        label.style.display = 'none';
      });
    }
  }
});

document.addEventListener('keyup', function(event) {
  if (event.key === 'Alt' || event.code === 'AltLeft' || event.code === 'AltRight') {
    altPressed = false;

    // Select all elements with the classes label-to-show and label-to-hide
    const showLabels = document.querySelectorAll('.label-to-show');
    const hideLabels = document.querySelectorAll('.label-to-hide');

    // Hide all elements with label-to-show
    showLabels.forEach(label => {
      label.style.display = 'none';
    });

    // Show all elements with label-to-hide
    hideLabels.forEach(label => {
      label.style.display = 'block';
    });
  }
});

// Function to equalize the height of the Kanban columns
function equalizeKanbanColumnsHeight() {
  const columns = document.querySelectorAll('.task-list-items');
  if (columns.length === 0) return;

  // First, reset the heights
  columns.forEach(column => {
    column.style.height = 'auto';
  });

  // Find the maximum height
  let maxHeight = 0;
  columns.forEach(column => {
    const height = column.offsetHeight;
    maxHeight = Math.max(maxHeight, height);
  });

  // Apply the maximum height to all columns
  columns.forEach(column => {
    column.style.height = `${maxHeight}px`;
  });
}

// Execute when the DOM is ready
document.addEventListener('DOMContentLoaded', function() {
  equalizeKanbanColumnsHeight();
});

// Execute when the window size changes
window.addEventListener('resize', function() {
  equalizeKanbanColumnsHeight();
});

// Execute when tasks are added or removed
const observer = new MutationObserver(function(mutations) {
  equalizeKanbanColumnsHeight();
});

// Observe changes in the Kanban columns
document.addEventListener('DOMContentLoaded', function() {
  const columns = document.querySelectorAll('.task-list-items');
  columns.forEach(column => {
    observer.observe(column, {
      childList: true,
      subtree: true
    });
  });
});

