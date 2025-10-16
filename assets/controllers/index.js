import { Application } from "@hotwired/stimulus";
import AuthController from "./auth_controller.js";




const application = Application.start();
application.register("auth", AuthController);
