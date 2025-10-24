import { Application } from "@hotwired/stimulus";
import AuthController from "./auth_controller.js";
import PasswordToggleController  from "./credention/password-toggle_controller.js";
import PasswordStrengthController   from "./credention/password-strength_controller.js";
import LayoutController   from "./components/layout_controller.js";




const application = Application.start();
application.register("auth", AuthController);
application.register('password-toggle', PasswordToggleController);
application.register('password-strength', PasswordStrengthController);
application.register("layout", LayoutController);